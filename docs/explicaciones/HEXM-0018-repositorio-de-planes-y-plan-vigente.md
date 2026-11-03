# Explicación HEXM-0018: Repositorio de planes y plan vigente

## Explicación

Para responder «¿qué me toca hoy?» hay que saber primero **qué plan está vigente hoy**. El ADR 0010 decidió que la vigencia no se guarda, sino que se deriva: un plan está vigente desde su lunes de inicio hasta que empieza el siguiente. Esa pregunta compara **varios planes**, así que ningún `NutritionPlan` puede responderla solo.

Esta tarea crea el lugar que ve todos los planes, el **repositorio**, y le da dos reglas del negocio como parte de su contrato:

1. **El plan vigente en una fecha** es el de inicio más reciente que no pasa de ella.
2. **Dos planes distintos no pueden empezar el mismo día**, porque entonces la vigencia sería ambigua.

La idea de la solución:

- un **puerto de salida** en el dominio, `NutritionPlanRepository`, con una consulta que lleva el nombre del negocio: `inForceOn(fecha)`;
- un **adaptador en memoria** que lo implementa;
- unos **tests de contrato** que fijan el comportamiento y que deberá pasar también el adaptador de Eloquent del nivel 5.

## Lógica de negocio

- **Vigencia.** Con el plan 26 (desde el lunes 7 de septiembre de 2026) y el 27 (desde el lunes 2 de noviembre):

  | Fecha | Plan vigente |
  |---|---|
  | 01/08 | Ninguno: todavía no había empezado ninguno. |
  | 07/09 | El 26: el día de inicio ya cuenta. |
  | 15/10 | El 26. |
  | 01/11 a las 23:59 | El 26: su último minuto. |
  | 02/11 | El 27, que cierra el 26 sin que nadie tenga que hacerlo a mano. |
  | 20/12 | El 27: sigue vigente hasta que llegue otro plan. |

- **Da igual el orden en que se registren los planes.** Si primero se da de alta el 27 y después, como histórico, el 26, el resultado es el mismo.
- **Solo cuenta la fecha, no la hora.** A las 00:30 del 2 de noviembre en Madrid ya rige el plan 27, aunque en UTC todavía sea día 1.
- **Un solo plan vigente en cada fecha** (regla 5). Si la nutricionista da el plan 28 con el mismo lunes de inicio que el 27, seguramente es un error: no puede haber dos planes vigentes el mismo día. Se rechaza con un mensaje en español: *«El plan 28 no puede empezar el 02/11/2026: ese día ya empieza el plan 27.»* El plan 27 sigue guardado y vigente.
- **Corregir un plan no es registrar otro.** Volver a guardar el mismo plan, aunque cambie su número o sus menús, lo reemplaza. No cuenta como «otro plan el mismo día».

## Decisiones de arquitectura

**El puerto vive en el dominio y el adaptador en la infraestructura.** `NutritionPlanRepository` y `DuplicatedPlanStart` están en `MealPlanning\Domain`; `InMemoryNutritionPlanRepository`, en `MealPlanning\Infrastructure` (su primer fichero). La dependencia apunta hacia dentro: el adaptador implementa la interfaz del dominio, y el dominio no sabe que existe el adaptador (**inversión de dependencias**, igual que en el recetario).

**Reglas entre agregados en el contrato del puerto (ADR 0011).**

```
                ┌──────────── MealPlanning\Domain ────────────┐
 Tests de       │  NutritionPlanRepository (puerto)           │
 contrato ─────►│   · save(): rechaza otro plan el mismo día  │──► DuplicatedPlanStart
 (HEXM-0019:    │   · find()                                  │
 GetMenuFor-    │   · inForceOn(): inicio más reciente ≤ fecha│──► NutritionPlan
 Date)          └──────────────────▲──────────────────────────┘
                                   │ implementan (mismo contrato)
              ┌────────────────────┴───────────────────────┐
     InMemoryNutritionPlanRepository        Eloquent (nivel 5)
     (bucle en PHP)                         (ORDER BY + índice único)
```

- La regla está escrita **una vez**, como especificación: en el docblock de la interfaz y en `NutritionPlanRepositoryContractTest`.
- Cada adaptador la **ejecuta a su manera**: con un bucle en PHP, o con `WHERE starts_on <= ? ORDER BY starts_on DESC LIMIT 1` y un índice único en PostgreSQL.
- Los tests de contrato garantizan que todos los adaptadores se comportan igual. El *dataset* tiene hoy un solo adaptador; en el nivel 5 se añadirá Eloquent con una línea más.

**Por qué rechazar en `save()` y no en un caso de uso.** Si un caso de uso comprobara «¿hay ya un plan ese día?» antes de guardar, dos registros simultáneos podrían pasar los dos la comprobación. Un índice único en la base de datos no tiene ese hueco. Por eso la regla vive donde se guarda. La excepción es del dominio, y en el nivel 5 el adaptador de Eloquent traducirá la violación del índice a esta misma excepción: el caso de uso nunca verá un `QueryException` de Laravel.

**Una consulta con nombre del negocio.** `inForceOn()` no es un `findBy(['starts_on' => …])`. El caso de uso de HEXM-0019 preguntará «¿qué plan está vigente?» sin saber cómo se calcula la vigencia.

**Sin copias defensivas.** El repositorio de recetas clona al guardar y al leer porque `Recipe` es mutable. `NutritionPlan` es `readonly` y todo lo que contiene es inmutable: compartir el mismo objeto es seguro.

**Fechas del calendario.** El adaptador compara el texto `AAAA-MM-DD` de cada fecha en su zona horaria, como `MealCalendar` (HEXM-0017). En ese formato ISO, ordenar los textos es lo mismo que ordenar las fechas.

**`NutritionPlanMother`.** Hasta ahora cada fichero de test montaba su plan con funciones auxiliares, que en Pest son globales y obligaban a inventar prefijos (`plan27…`, `calendar…`). Un *Object Mother* en `tests/Support/MealPlanning`, como `RecipeMother`, tiene su namespace y lo podrán usar HEXM-0019 y el nivel 5.

## Alternativas descartadas

- **Cómo se haría en un Laravel clásico.** Un *scope* en el modelo Eloquent (`NutritionPlan::query()->where('starts_on', '<=', now())->latest('starts_on')->first()`), usado directamente desde un controlador, y quizá una regla `unique:nutrition_plans,starts_on` en un `FormRequest`. Funciona, pero la vigencia queda atada a Eloquent y a la base de datos: no se puede probar sin PostgreSQL, y la regla de unicidad solo se cumple si los datos entran por ese formulario. Aquí la vigencia es una pregunta del dominio, con un contrato que cumple cualquier adaptador.
- **Guardar una fecha de fin y buscar por rango** (`starts_on <= X AND (ends_on IS NULL OR ends_on > X)`). Descartado en el ADR 0010: duplica un dato.
- **Permitir dos planes el mismo día y desempatar por número.** Es más flexible, pero deja en el almacén dos planes que se pisan y esconde lo que casi seguro es un error.
- **Que lo compruebe un caso de uso «registrar plan».** Tiene el problema de los registros simultáneos explicado arriba y, mientras no existiera, `inForceOn()` quedaría sin definir ante un empate.
- **Un `findAll()` y calcular la vigencia fuera.** Obligaría al caso de uso a conocer la regla y a cargar todos los planes. Con una consulta del puerto, el adaptador de Eloquent podrá resolverla con una sola consulta SQL.
- **Clonar como en el recetario.** No hace falta con un objeto inmutable. Si el plan llega a tener métodos de cambio (nivel 8), habrá que añadirlo, y un test de contrato lo detectará.
- **Poner ya los tests de contrato en `tests/Feature`.** No hace falta Laravel mientras solo haya el adaptador en memoria, y la tarea pedía tests sin Laravel. Se moverán cuando llegue PostgreSQL.

## Conceptos aplicados

| Concepto | Definición breve | Dónde verlo |
|---|---|---|
| Reglas entre agregados | Lo que ningún agregado puede garantizar solo se resuelve donde se ven todos: el repositorio. | `NutritionPlanRepository` (bloque `CONCEPTO:`) y ADR 0011 |
| Puerto de salida e inversión de dependencias | Interfaz en el dominio que la infraestructura implementa. | `NutritionPlanRepository` e `InMemoryNutritionPlanRepository` |
| Consulta con nombre del negocio | `inForceOn(fecha)` en lugar de un `findBy` genérico. | `NutritionPlanRepository::inForceOn()` |
| Tests de contrato | Una batería de tests que cumple cualquier adaptador del puerto, con un *dataset* de Pest. | `tests/Unit/MealPlanning/Infrastructure/NutritionPlanRepositoryContractTest.php` |
| Error de dominio lanzado por un adaptador | La regla y su excepción son del dominio aunque quien la detecta es la infraestructura. | `DuplicatedPlanStart` |
| Inmutabilidad frente a copias defensivas | Un objeto inmutable se comparte sin riesgo; uno mutable hay que clonarlo. | `InMemoryNutritionPlanRepository` (bloque `DECISIÓN:`) frente a `InMemoryRecipeRepository` |
| Object Mother | Clase que crea objetos de ejemplo válidos para los tests. | `tests/Support/MealPlanning/NutritionPlanMother.php` |
| Tests de mutación | Estropear a propósito una decisión para ver qué test la defiende: sin el rechazo de duplicados, con `<` en lugar de `<=` y comparando instantes en vez de fechas, falla algún test. | Documento de Pruebas |

## Diagramas

- [Repositorio de planes](../diagramas/HEXM-0018/repositorio-de-planes.architecture.json) (`architecture`): ¿dónde viven el puerto, su contrato y sus adaptadores, y quién lanza el error de los planes que empiezan el mismo día?
- [Vigencia de los planes 26 y 27](../diagramas/HEXM-0018/vigencia-de-los-planes.timeline.json) (`timeline`): ¿qué plan está vigente en cada fecha de ejemplo y qué pasa al intentar guardar un plan 28 el mismo día que el 27?

Para verlos: `cd app-laravel && composer docs:diagrams` y abrir `docs/diagramas/HEXM-0018/<slug>.html` en el navegador.
