# Explicación HEXM-0020: Repositorio Eloquent de planes

## Explicación

Hasta ahora los planes solo existían en memoria, durante la ejecución de un test. Para que la aplicación recuerde el plan 27 de un día para otro, hacía falta un segundo adaptador del puerto `NutritionPlanRepository` que los guarde en PostgreSQL. La condición era la misma que en el nivel 2 con las recetas: **ni el dominio ni la aplicación pueden cambiar**. `GetMenuForDate` tiene que seguir funcionando igual, sin saber si el plan viene de un array o de una base de datos.

La pregunta difícil no era técnica, sino de diseño: **cómo repartir en la base de datos un agregado de cuatro niveles**. Un plan tiene pautas fijas con grupos de opciones, una tabla de intercambios con reglas y dos semanas de seis días, cada día con dos platos, y cada plato puede llevar ingredientes.

La idea de la solución:

- **una fila por plan**: columnas normales para lo que se consulta (`id`, `number`, `starts_on`) y el contenido del agregado en tres **documentos `jsonb`**;
- un ***mapper*** que traduce el agregado a esos documentos y, al leer, los trata como datos no confiables: valida cada campo y reconstruye el plan con los constructores del dominio;
- un **índice único** en `starts_on`, que hace que PostgreSQL cumpla la regla «dos planes no empiezan el mismo día»; el adaptador traduce su violación al error del dominio;
- los **tests de contrato** del puerto, ejecutados ahora contra los dos adaptadores.

## Lógica de negocio

No aparece ninguna regla nueva: esta tarea consiste en **conservar** las que ya existían cuando los planes se guardan de verdad.

- **El plan vuelve entero.** Si se guarda el plan 27, al leerlo tienen que volver:
  - el desayuno (café con leche desnatada, más una opción a elegir entre la tostada con tomate y los copos de avena);
  - la media tarde;
  - la tabla de intercambios (arroz, 60 g en crudo; pechuga de pollo, 100 g) con la regla «la grasa solo en la comida o en la cena»;
  - la semana 1 con los menús de la nutricionista en texto libre y la semana 2 del usuario, con el miércoles «Comida del día 10» y sus 80 g de pasta integral.

  Nada se pierde, se reordena ni cambia de formato.
- **El plan vigente se calcula igual** (regla 19): el 11 de noviembre rige el 27; el 15 de octubre, el 26; y el 1 de noviembre a las 23:59 todavía el 26.
- **Dos planes no pueden empezar el mismo día** (regla 5). Si llega un plan 28 con el mismo lunes de inicio que el 27, se rechaza con el mismo mensaje de siempre: *«El plan 28 no puede empezar el 02/11/2026: ese día ya empieza el plan 27.»*
- **Corregir la fecha de un plan está permitido, pero no a un día ocupado.** Si el plan 27 ya guardado se intenta mover al 7 de septiembre, el lunes en que empieza el 26, se rechaza igual. Este caso no estaba en los tests de contrato y apareció durante esta tarea.
- **Lo que no cumple las reglas no entra en la aplicación, ni siquiera desde la base de datos.** Si alguien edita a mano un plan guardado y deja dos martes en la semana 1, al cargarlo salta el mismo error que al crearlo.

## Decisiones de arquitectura

### Dónde está cada pieza

```
MealPlanning\Domain           NutritionPlanRepository (puerto), NutritionPlan, DuplicatedPlanStart  ← sin cambios
MealPlanning\Application      GetMenuForDate                                                     ← sin cambios
MealPlanning\Infrastructure
 ├── InMemoryNutritionPlanRepository              (adaptador 1: tests)
 └── Persistence\Eloquent
      ├── EloquentNutritionPlanRepository          (adaptador 2: PostgreSQL)
      ├── NutritionPlanMapper                      (agregado ↔ fila con documentos)
      └── NutritionPlanModel                       (la fila, para Eloquent)
database/migrations/…_create_nutrition_plans_table.php
```

Las dependencias apuntan hacia dentro: el adaptador implementa la interfaz del dominio y conoce el agregado, pero el dominio no sabe que existe. La prueba es un `git diff develop` **vacío** en `MealPlanning\Domain` y `MealPlanning\Application`. Es la promesa central de la arquitectura hexagonal: la base de datos es un detalle que se enchufa desde fuera.

### El agregado como documento (ADR 0013)

El recetario (ADR 0006) repartió `Recipe` en tres tablas. Aquí se hizo lo contrario, por tres hechos del dominio:

1. el plan **siempre se lee y se escribe entero**: nadie pide «el miércoles de la semana 2» por separado;
2. las dos reglas del repositorio (la vigencia y un inicio por día) **solo miran `starts_on`**;
3. las partes **no tienen identidad propia**: son *value objects* o entidades locales.

Lo que el dominio trata como una unidad, la base de datos también. Con una sola fila no hay tablas hijas que borrar y reinsertar, y no pueden quedar filas huérfanas. `jsonb` (y no `json`) porque se guarda ya analizado, se puede indexar y admite operadores como `jsonb_set` o `jsonb_typeof`.

Un `CHECK` exige el tipo de cada documento y exactamente dos semanas. Es una red mínima para los cambios a mano: la forma completa la valida el dominio.

### El JSON guardado es una entrada no confiable

Con columnas tipadas, PostgreSQL garantiza los tipos. Dentro de un `jsonb`, no: un `UPDATE` a mano puede dejar un número donde iba el nombre de un plato. Por eso el *mapper* lee de forma defensiva, como una *Form Request* pero en el otro extremo de la aplicación. Cada valor se comprueba con funciones como `string()`, `int()`, `list()` o `enum()`, que en términos de tipos **estrechan un `mixed`**. Si un dato no cuadra, el error dice su ruta exacta: *«Plan guardado corrupto: «weeks[1].days[2].lunch.name» debería ser un texto.»*.

Después, el plan se reconstruye con `NutritionPlan::create()`, `MenuWeek::create()`, `MenuDay::of()`… Las invariantes se vuelven a comprobar al cargar, como decidió el ADR 0006.

### Recorrido de un guardado con duplicado

```
save(plan 27 → 07/09)
  ├─ mapper.toRow(plan)                          agregado → fila con documentos
  ├─ transaction()  ──►  SAVEPOINT               (anidada dentro de otra transacción)
  │     UPDATE … starts_on = '2026-09-07'  ──►  violación del índice único
  │  ◄── ROLLBACK TO SAVEPOINT                   la transacción de fuera sigue usable
  ├─ SELECT … WHERE starts_on = '2026-09-07'     el plan que ocupa ese día (el 26)
  └─ throw DuplicatedPlanStart(27, 26)            el mismo error de dominio que en memoria
```

### Por qué hay transacción con una sola fila

Una fila es una sola sentencia, y PostgreSQL ya la hace atómica, así que la transacción no está para eso. Está porque en PostgreSQL **un error aborta la transacción que lo contiene**: cualquier consulta posterior falla hasta el `ROLLBACK`. Si `save()` se ejecuta dentro de otra transacción y salta el índice único, no se podría buscar el plan que ocupa ese día para escribir el mensaje. Ocurre con la de `RefreshDatabase` en los tests y ocurrirá con la de un caso de uso en HEXM-0021.

Una `transaction()` anidada se convierte en un `SAVEPOINT`, y deshacer hasta él deja la conexión usable.

No se dio por supuesto: se comprobó quitando la transacción.

- **Plan nuevo en un día ocupado (`INSERT`)**: seguía funcionando, porque `updateOrCreate()` crea con `createOrFirst()`, y Laravel ya envuelve ese `INSERT` en un *savepoint* por este mismo motivo.
- **Plan ya guardado movido a un día ocupado (`UPDATE`)**: fallaba con `SQLSTATE[25P02] current transaction is aborted`.

El segundo caso ni siquiera estaba en los tests de contrato. Ahora sí está, y lo protege la transacción.

### Los tests de contrato, con un adaptador real

`NutritionPlanRepositoryContractTest` pasó de `tests/Unit` a `tests/Feature`, con `RefreshDatabase`, y el adaptador nuevo fue una línea más en el *dataset*. Se añadieron dos tests al contrato:

- `toEqual()` compara el plan recuperado con el original **propiedad a propiedad**. `equals()` de un agregado solo compara la identidad, y un *mapper* que perdiera un dato pasaría desapercibido;
- el caso del `UPDATE` a un día ocupado.

Como los dos adaptadores cumplen el mismo contrato, los tests rápidos de `GetMenuForDate`, que usan el adaptador en memoria, valen también para producción.

## Alternativas descartadas

- **Un Laravel clásico**: un modelo `NutritionPlan` de Eloquent en `app/Models`, con relaciones `hasMany` hasta los ingredientes, usado directamente por los controladores. El modelo de datos y el de negocio serían el mismo, y las reglas (seis días por semana, el sábado libre, el lunes de inicio) acabarían repartidas entre validaciones y *observers*. Aquí Eloquent es un detalle del adaptador: `NutritionPlanModel` nunca sale de `Infrastructure`.
- **Todo normalizado** (unas nueve tablas, como el recetario pero con cuatro niveles). Es lo más relacional y permite un `CHECK` en cada columna. Pero supone mucho mapeo, muchas consultas y borrar y reinsertar tablas hijas para un agregado que siempre se usa entero.
- **Un híbrido** (el plan con documentos y una tabla `menu_days`). Solo compensaría si hiciera falta consultar días sueltos, y hoy nada lo pide.
- **Comprobar el duplicado con un `SELECT` antes de guardar**, como hace el adaptador en memoria. Con dos guardados a la vez, los dos pasarían la comprobación. El índice único lo garantiza siempre; el `SELECT` posterior solo sirve para el mensaje.
- **`upsert()` en una sola sentencia (`INSERT … ON CONFLICT`)**. No aplica los *casts* del modelo (habría que codificar el JSON a mano) y no aporta nada frente a `updateOrCreate()` para un plan personal.
- **Un `NutritionPlan::reconstitute()` que no valide al cargar.** Lo descartó el ADR 0006 por las mismas razones: validar cuesta poco, y con documentos JSON la validación es todavía más valiosa.
- **Un cast `date` en `starts_on`**: daría un `Carbon`, que el dominio no usa. La columna `date` ya llega como `'AAAA-MM-DD'`, una fecha del calendario sin hora.

## Conceptos aplicados

| Concepto | Definición breve | Dónde verlo |
|---|---|---|
| Segundo adaptador del mismo puerto | Otra implementación de la interfaz del dominio; el núcleo no cambia. | `app-laravel/src/MealPlanning/Infrastructure/Persistence/Eloquent/EloquentNutritionPlanRepository.php` |
| El agregado como documento | Guardar como una unidad lo que el dominio usa como una unidad: una fila con documentos `jsonb`. | `app-laravel/database/migrations/2026_11_04_000000_create_nutrition_plans_table.php`, ADR 0013 |
| Data Mapper | Un traductor entre el modelo de dominio y el de persistencia, que ninguno de los dos conoce. | `app-laravel/src/MealPlanning/Infrastructure/Persistence/Eloquent/NutritionPlanMapper.php` |
| Lectura defensiva (estrechar tipos) | Comprobar en tiempo de ejecución un `mixed` venido de fuera antes de usarlo, con la ruta del dato si falla. | `NutritionPlanMapper` (`list()`, `object()`, `string()`, `enum()`…) |
| Genéricos en PHPDoc | `@template` permite a PHPStan saber que `enum(MealType::class, …)` devuelve un `MealType`. | `NutritionPlanMapper::enum()` |
| Reconstitución con invariantes | Cargar con los constructores del dominio: un documento inválido falla al leerlo. | `NutritionPlanMapper::toDomain()` |
| Regla de negocio en un índice único | La base de datos garantiza la regla 5 también con concurrencia; el adaptador la traduce a `DuplicatedPlanStart`. | Migración (`starts_on` único) y `EloquentNutritionPlanRepository::save()` |
| Transacción abortada y `SAVEPOINT` | En PostgreSQL un error aborta la transacción; una transacción anidada es un *savepoint* al que volver. | `EloquentNutritionPlanRepository::save()` |
| `CHECK` sobre JSON | Una red mínima en la base de datos (`jsonb_typeof`, `jsonb_array_length`). | Migración (`nutrition_plans_documents_check`) |
| Tests de contrato | La misma especificación del puerto, ejecutada contra todos sus adaptadores. | `app-laravel/tests/Feature/MealPlanning/Infrastructure/NutritionPlanRepositoryContractTest.php` |

## Diagramas

- [Un plan, una fila](../diagramas/HEXM-0020/un-plan-una-fila.tree.json) (`tree`): ¿cómo queda guardado un plan? La fila de `nutrition_plans`, sus columnas para consultar y la estructura de cada documento `jsonb`, hasta los ingredientes de un plato.
- [Guardar un plan en un día ocupado](../diagramas/HEXM-0020/guardar-en-un-dia-ocupado.sequence.json) (`sequence`): ¿qué pasa cuando salta el índice único? El `SAVEPOINT`, la violación, la vuelta atrás, la consulta del plan que ocupa el día y `DuplicatedPlanStart`.

Para verlos: `cd app-laravel && composer docs:diagrams` y abrir `docs/diagramas/HEXM-0020/un-plan-una-fila.html` y `docs/diagramas/HEXM-0020/guardar-en-un-dia-ocupado.html` en el navegador.
