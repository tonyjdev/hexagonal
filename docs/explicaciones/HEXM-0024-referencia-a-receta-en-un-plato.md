# Explicación HEXM-0024: Referencia a receta en un plato

## Explicación

Algunos platos de los planes tienen receta: el *guiso de alubias y pollo* del menú 6 viene detallado en el plan, y el usuario lo tiene en su recetario. Hasta ahora los dos mundos estaban desconectados. Un plato era texto (o un nombre con ingredientes), y una receta vivía en otro contexto sin relación con los planes.

Esta tarea crea **el primer vínculo entre los dos *bounded contexts***: un plato puede apuntar a una receta. El problema no es guardar un identificador, sino **cómo nombrarlo sin romper la frontera**. El núcleo de `MealPlanning` no puede usar nada de `Recipes`, y los tests de arquitectura lo comprueban en cada ejecución.

La idea de la solución:

- un ***value object* propio de `MealPlanning`**, `RecipeReference`, que envuelve el mismo `Uuid` técnico que usan todos los identificadores. Los dos contextos solo comparten **el valor** del identificador, no el tipo;
- el plato guarda **solo la referencia**, nunca la receta;
- el documento `jsonb` del plan gana el campo `recipe_id` **sin migrar datos**: los planes guardados antes se leen igual.

## Lógica de negocio

- **Un plato puede tener receta, o no** (regla 6). En la semana 1, la comida del menú 6, «Guiso de alubias y pollo», apunta a su receta; la cena del mismo día no tiene ninguna. En la semana 2 vale igual: «Pollo al curry con arroz» puede apuntar a una receta y seguir teniendo sus ingredientes con cantidades.
- **El plato no copia la receta.** Conserva su texto, su nombre y sus ingredientes tal como los escribió la nutricionista o el usuario, y añade una flecha hacia la receta. Si la receta cambia en el recetario, el plato no necesita cambiar.
- **Dos platos iguales en texto, pero con recetas distintas, no son el mismo plato.**
- **Que la receta exista no lo decide el plato.** El plan no puede saberlo por sí solo; lo comprobará el alta del plan, preguntando al recetario (HEXM-0025). Qué pasa si después se borra la receta queda para HEXM-0027.
- **Los planes que ya existían no cambian**: sus platos, simplemente, no tienen receta.

## Decisiones de arquitectura

### Un tipo propio por contexto (ADR 0016)

```
Recipes\Domain                 Shared\Domain                MealPlanning\Domain
 RecipeId ───── envuelve ────►  Uuid  ◄──── envuelve ───── RecipeReference ◄── 0..1 ── Dish
 (identidad de su agregado)    (técnica)                    (referencia hacia fuera)
         ✗ ninguna flecha entre Recipes y MealPlanning (tests de arquitectura) ✗
```

- **`RecipeReference` vive en `MealPlanning\Domain`** y envuelve el `Uuid` del *shared kernel*, como `NutritionPlanId`.
  - Por fuera es casi un gemelo de `RecipeId`, pero es otro tipo: PHPStan no deja pasar uno donde se espera el otro.
  - Su error (`InvalidRecipeReference`) también es propio y está en español.
- **El nombre marca el papel.** En `Recipes`, `RecipeId` es la **identidad** de su agregado. En `MealPlanning`, una receta solo existe como **referencia hacia fuera**, que incluso podría apuntar a algo que ya no existe.
- **Referencia por identidad, nunca el objeto** (ADR 0010). Meter la `Recipe` dentro del `Dish` uniría dos agregados de dos contextos: cambiar una receta obligaría a guardar los planes que la usan, y `MealPlanning` dependería del modelo de `Recipes`.

### Un plato inmutable con *wither*

`Dish` es un *value object* inmutable. La referencia se añade con `withRecipe()`, que **devuelve un plato nuevo**: es el equivalente inmutable de un *setter*, como `DateTimeImmutable::modify()` devuelve otra fecha. Vale para los dos formatos (`described()` y `named()`) sin duplicar constructores, y la igualdad del plato incluye la receta.

### Evolución compatible del documento `jsonb`

El plan se guarda como documentos `jsonb` (ADR 0013). Cada plato del documento `weeks` gana la clave `recipe_id`, con un UUID o `null`. Al leer:

- **clave ausente** (planes anteriores) o `null` → plato sin receta;
- **un UUID** → plato con receta;
- **cualquier otra cosa** → datos corruptos, y el plan no se carga (`InvalidRecipeReference`), como cualquier otro dato que no cuadra.

Tratar la clave ausente como `null` es lo que permite **no migrar datos ni versionar el documento**: los documentos viejos y los nuevos conviven. Un test lo demuestra quitando la clave de todos los platos con SQL `jsonb` y cargando el plan.

### La lectura, sin cambiar la forma de las vistas

`DishView` gana `recipeId` como último argumento, con `null` por defecto, y los dos *API Resources* exponen `recipe_id`. Es un cambio **aditivo** del contrato JSON: no se quita ni se renombra nada.

## Alternativas descartadas

- **Un Laravel clásico**: una clave foránea `dish.recipe_id → recipes.id` y una relación `belongsTo(Recipe::class)` en el modelo del plato, con `$dish->recipe->ingredients` desde cualquier sitio. Uniría los dos contextos por la base de datos y por el código: borrar una receta rompería la clave foránea de los planes, y `MealPlanning` dependería del modelo de `Recipes`. Aquí no hay clave foránea entre contextos (el plan es un documento) ni importaciones cruzadas.
- **Mover `RecipeId` a `Shared\Domain`**: un solo tipo, pero metería un concepto del negocio del recetario en el núcleo común. Es justo lo que el README de `Shared` desaconseja (el caso de `Quantity`), y acoplaría los dos contextos a cada cambio del identificador.
- **Guardar el id como un `string` suelto** en el plato: se pierde la validación y el tipo, y un id de plan podría pasar por uno de receta sin que nadie lo notara.
- **Migrar los documentos guardados** para añadir `recipe_id: null` a cada plato: es trabajo y riesgo para nada, porque la lectura compatible cubre el caso.
- **Validar aquí que la receta existe**: el dominio de `MealPlanning` no puede saberlo sin preguntar a otro contexto. Eso es la capa anticorrupción de HEXM-0025.

## Conceptos aplicados

| Concepto | Definición breve | Dónde verlo |
|---|---|---|
| Referencia entre agregados por identidad | Un agregado apunta a otro solo por su id, nunca lo contiene. | `app-laravel/src/MealPlanning/Domain/Dish.php` (`withRecipe()`, `recipe()`) |
| Un tipo por concepto y por contexto | El mismo UUID, dos tipos (`RecipeId`, `RecipeReference`) que el analizador no deja confundir. | `app-laravel/src/MealPlanning/Domain/RecipeReference.php` |
| *Shared kernel* mínimo | Se comparte la técnica (`Uuid`), no los conceptos del negocio. | `app-laravel/src/Shared/Domain/Uuid.php`, `app-laravel/src/Shared/README.md` |
| *Wither* en un *value object* inmutable | Un método `with…()` que devuelve una copia cambiada. | `Dish::withRecipe()` |
| Evolución compatible de un documento | Un campo nuevo opcional se lee igual si falta: sin migración ni versión. | `NutritionPlanMapper::dishToDomain()` |
| Fronteras entre contextos comprobadas | Los tests de arquitectura impiden que el núcleo de un contexto use otro. | `app-laravel/tests/Architecture/HexagonalLayersTest.php` |
| Cambio aditivo de una API | Añadir claves sin quitar ni renombrar ninguna. | `ScheduledDayResource`, `NutritionPlanResource` |

## Diagramas

- [Un UUID, dos tipos](../diagramas/HEXM-0024/un-uuid-dos-tipos.class.json) (`class`): ¿cómo nombra cada contexto a una receta sin depender del otro? Muestra `RecipeId` y `RecipeReference` envolviendo el mismo `Uuid`, el `Dish` que apunta a 0..1 receta y su error.
- [Leer el recipe_id de un plato](../diagramas/HEXM-0024/leer-el-recipe-id.workflow.json) (`workflow`): ¿qué pasa al cargar un plato de un plan viejo o nuevo? Muestra los tres caminos: sin receta, con receta y datos corruptos.

Para verlos: `cd app-laravel && composer docs:diagrams` y abrir `docs/diagramas/HEXM-0024/un-uuid-dos-tipos.html` y `docs/diagramas/HEXM-0024/leer-el-recipe-id.html` en el navegador.
