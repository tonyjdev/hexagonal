# Explicación HEXM-0021: Alta manual de un plan por API

## Explicación

Hasta ahora un plan solo podía nacer dentro de un test, montado a mano con el `NutritionPlanMother`. Para usar la aplicación de verdad hace falta **darlo de alta desde fuera**: cada dos meses la nutricionista entrega un plan nuevo, y alguien tiene que meterlo.

Es el primer **comando** de `MealPlanning`, y el agregado más grande que entra desde fuera en el proyecto. Los datos de un plan tienen tres formas distintas a lo largo de la aplicación:

1. **en el papel** (y en el JSON de la petición): la semana 1 con menús del 1 al 6 y la semana 2 con días del 8 al 14;
2. **en el dominio**: dos semanas organizadas por día de la semana, con todas sus reglas comprobadas;
3. **en la base de datos**: una fila con documentos `jsonb` (HEXM-0020).

La idea de la solución es que cada capa haga solo su parte:

- **la frontera HTTP** comprueba que la petición tiene la forma del contrato y la convierte en un comando;
- **el caso de uso** traduce el comando al agregado, sin decidir nada del negocio;
- **el dominio** decide si el plan es válido;
- **el repositorio** lo guarda y vigila que no haya otro plan el mismo día.

Por último, una raíz de composición propia (`MealPlanningServiceProvider`) enchufa el puerto de planes al adaptador de PostgreSQL.

## Lógica de negocio

- **Se copia lo que pone el papel.** La nutricionista entrega el «menú 1, menú 2… menú 6», sin días. El usuario escribe su semana como «día 8, día 9… día 14» y deja el 13 en blanco, porque es sábado. El alta acepta exactamente eso, y es la aplicación la que sabe que **el menú 6 es el domingo** y que **el día 10 es el miércoles** (regla 2).
- **El sábado es libre en las dos semanas.** El día 13 puede ir vacío o ni aparecer, pero si trae una «Pizza casera», se rechaza: *«El sábado es día libre: no puede tener menú.»*
- **Un plan empieza en lunes** (regla 18). Si se da de alta el plan 27 con inicio el martes 3 de noviembre: *«Un plan empieza siempre en lunes, y el 03/11/2026 es martes.»*
- **Seis días con menú, y cada uno con comida y cena** (reglas 2 y 3). Con cinco menús: *«Una semana debe tener 6 días con menú (de lunes a viernes y el domingo), y esta tiene 5.»* Si al menú 1 le falta la cena: *«Al menú del lunes le falta la cena.»*
- **Nada se repite.** Si el menú 1 aparece dos veces: *«El menú 1 aparece más de una vez en la semana 1.»* Las pautas fijas solo pueden ser desayuno, media mañana y media tarde, y los intercambios, de una de sus cuatro categorías: un «brunch» o unos intercambios de «dulces» se rechazan con un mensaje que dice cuáles valen.
- **Un solo plan vigente en cada fecha** (regla 5). Si ya existe el plan 27 desde el 2 de noviembre, un plan 28 con el mismo inicio se rechaza: *«El plan 28 no puede empezar el 02/11/2026: ese día ya empieza el plan 27.»*
- **Todo o nada.** Si algo está mal, no se guarda ninguna parte del plan.

## Decisiones de arquitectura

### El recorrido de un alta

```
POST /api/nutrition-plans  (JSON con el formato de los documentos)
   │
   ▼  MealPlanning\Infrastructure\Http
RegisterNutritionPlanRequest ── ¿forma incorrecta? ──► 422 {"message", "errors": {campo: […]}}
   │ toCommand(): primitivos + DateTimeImmutable
   ▼
RegisterNutritionPlanController
   │ RegisterNutritionPlanCommand
   ▼  MealPlanning\Application
RegisterNutritionPlan ── traduce: MealType, ExchangeCategory, menús y días por número
   │
   ▼  MealPlanning\Domain
MenuWeek::fromNutritionistMenus() / fromSelfDays()   (el menú 6 → domingo)
NutritionPlan::create(...)  ── ¿regla incumplida? ──► DomainException → 422 {"message"} (manejador global)
   │
   ▼  puerto NutritionPlanRepository  (MealPlanningServiceProvider → Eloquent)
EloquentNutritionPlanRepository::save() ── ¿otro plan ese día? ──► DuplicatedPlanStart → 422
   │
   ▼
201 {"id": "…"}
```

Las dependencias apuntan hacia dentro: el controlador conoce el caso de uso; el caso de uso, el dominio y su puerto; Eloquent implementa el puerto. Nadie del núcleo sabe que existen HTTP o PostgreSQL.

### El contrato copia el papel (ADR 0014)

El JSON tiene la forma de los documentos: `nutritionist_week` con `{menu, lunch, dinner}` en texto, y `self_week` con `{day, lunch: {name, ingredients}, dinner}`. El caso de uso pasa cada semana por el **constructor de formato** del dominio (`MenuWeek::fromNutritionistMenus()` y `fromSelfDays()`).

La traducción «menú 6 → domingo» es una regla del negocio, y así sigue escrita una sola vez, en el dominio. Quien envía el plan no tiene que saberla.

Este JSON es también **el contrato natural del importador del nivel 7**: el servicio de FastAPI leerá el Excel y producirá esta misma forma.

### La frontera valida la forma; el dominio, las reglas (ADR 0007)

Con un payload grande se ve mejor la separación. La *Form Request* comprueba los campos, los tipos y las listas, y devuelve **todos** los errores con su ruta (`self_week.0.lunch.ingredients.0.quantity`) sin llegar al caso de uso. No comprueba:

- que haya seis menús o que el inicio sea lunes;
- que `meal_type` sea uno de los valores válidos (no hay `in:`).

Esas reglas las comprueba el dominio, y protegen igual al importador del nivel 7, que no pasa por esta *Form Request*. Por eso los textos son `nullable`: un plato vacío es una regla de negocio, no un problema de forma.

### El caso de uso traduce, no decide

`RegisterNutritionPlan` convierte cada primitivo en su objeto del dominio. Solo añade lo que el dominio no puede ver, porque solo existe al traducir:

- **Un texto que no es un `MealType` o un `ExchangeCategory`.** Dentro del dominio son enums cerrados.
- **Un menú o un día repetido.** Al indexar por número, el segundo pisaría al primero en silencio.

Se lanzan con **excepciones del dominio** (`InvalidFixedMeal::unknownMealType()`, `InvalidMenuWeek::duplicatedNutritionistMenu()`…), igual que `CreateRecipe` hace con `InvalidIngredient::unknownUnit()`, para que lleguen en español a cualquier adaptador.

### Todo o nada sin transacción en el caso de uso

El agregado se construye **entero antes de guardarlo**: si una regla falla, la excepción salta antes de llamar a `save()`. Y guardar es una sola fila (ADR 0013). El alta es atómica sin que el caso de uso abra una transacción.

### Una raíz de composición por contexto

`MealPlanningServiceProvider` es el único sitio que decide qué adaptador hay detrás de `NutritionPlanRepository` (Eloquent). Está en la infraestructura del propio contexto, igual que `RecipesServiceProvider`, y se registra en `bootstrap/providers.php`. Los tests unitarios no pasan por él: construyen el caso de uso a mano con el adaptador en memoria. Un test propio comprueba el cableado real.

## Alternativas descartadas

- **Un Laravel clásico**: un controlador que valida todo con reglas de Laravel (`in:breakfast,…`, `size:6`, `after_or_equal`…) y crea el modelo de Eloquent con sus relaciones con `Model::create()`. Las reglas del negocio quedarían en la capa HTTP: el importador del nivel 7 o un comando de Artisan tendrían que repetirlas, y acabarían divergiendo. Aquí están una sola vez, en el dominio.
- **Un JSON normalizado por días de la semana** (igual que se guarda). Es más uniforme, pero obliga a quien envía el plan a traducir «menú 6 → domingo», que es una regla del negocio, y deja sin usar los constructores de formato.
- **Las semanas como objetos JSON con el número como clave** (`{"1": {…}, "6": {…}}`). Es más corto, pero oculta el orden y hace invisibles los duplicados (un JSON con dos claves iguales se queda con la última). Por eso el número va dentro de cada elemento de la lista.
- **Validar `meal_type` y `category` en la *Form Request* con `in:`**. Daría un 422 algo antes, pero duplicaría el vocabulario del dominio en la frontera y no protegería a los otros adaptadores de entrada.
- **Recibir `starts_on` como texto en el comando**, como `GetRecipe` recibe el id. `DateTimeImmutable` es un tipo nativo y permitido en el núcleo; convertir el texto de la petición es tarea del adaptador (como en HEXM-0019).
- **Registrar el *binding* en `AppServiceProvider`**. Mezclaría el cableado de todos los contextos en un solo sitio; con un *provider* por contexto, cada uno se enchufa y se quita por su cuenta.
- **Responder con una cabecera `Location`**: no hay todavía una URL de consulta de un plan (llegará en HEXM-0023).

## Conceptos aplicados

| Concepto | Definición breve | Dónde verlo |
|---|---|---|
| Comando (CQS) | Un caso de uso que cambia el estado y solo devuelve el id de lo creado. | `app-laravel/src/MealPlanning/Application/RegisterNutritionPlan.php` |
| Comando como DTO de entrada | Datos primitivos hacia el núcleo, sin HTTP ni dominio. | `app-laravel/src/MealPlanning/Application/RegisterNutritionPlanCommand.php` |
| Traducción frente a decisión | El caso de uso convierte primitivos en objetos; las reglas las decide el dominio. | `RegisterNutritionPlan` (`toNutritionistWeek()`, `toSelfWeek()`…) |
| Constructores de formato (traducción del origen en el dominio) | El dominio sabe leer los formatos del negocio: menús 1-6 y días 8-14. | `app-laravel/src/MealPlanning/Domain/MenuWeek.php` |
| Validación de forma frente a reglas | La frontera comprueba el contrato; el dominio, el negocio (dos tipos de 422). | `app-laravel/src/MealPlanning/Infrastructure/Http/RegisterNutritionPlanRequest.php`, ADR 0007 |
| Adaptador *driving* | Traduce HTTP al lenguaje del núcleo y de vuelta, sin reglas. | `app-laravel/src/MealPlanning/Infrastructure/Http/RegisterNutritionPlanController.php` |
| Raíz de composición por contexto | El único sitio que elige el adaptador de cada puerto del contexto. | `app-laravel/src/MealPlanning/Infrastructure/MealPlanningServiceProvider.php` |
| Contrato de API del negocio | El JSON reproduce el documento de origen; servirá también al importador del nivel 7. | `app-laravel/tests/fixtures/nutrition-plan-27.json`, ADR 0014 |
| Object Mother compartido | Los datos de ejemplo (el plan y su comando de alta) en una clase reutilizable. | `app-laravel/tests/Support/MealPlanning/NutritionPlanMother.php` |

## Diagramas

- [Del papel a la base de datos](../diagramas/HEXM-0021/del-papel-a-la-base-de-datos.dataflow.json) (`dataflow`): ¿qué forma tiene el plan en cada capa y dónde se rechaza cada error? Muestra el JSON con menús y días, el comando, `MenuWeek` y `NutritionPlan` en el dominio, la fila `jsonb`, y los dos tipos de 422.
- [Hexágono de MealPlanning](../diagramas/HEXM-0021/hexagono-mealplanning.architecture.json) (`architecture`): ¿cómo se conectan las piezas del contexto? Muestra el controlador y la *Form Request*, el caso de uso, el agregado y el puerto, Eloquent y el *provider* que los enchufa.

Para verlos: `cd app-laravel && composer docs:diagrams` y abrir `docs/diagramas/HEXM-0021/del-papel-a-la-base-de-datos.html` y `docs/diagramas/HEXM-0021/hexagono-mealplanning.html` en el navegador.
