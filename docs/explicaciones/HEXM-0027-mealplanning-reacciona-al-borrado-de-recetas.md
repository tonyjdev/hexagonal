# Explicación HEXM-0027: MealPlanning reacciona al borrado de recetas

## Explicación

Desde HEXM-0026 se puede borrar una receta, y el recetario lo anuncia con `RecipeDeleted`. Pero nadie escuchaba: un plan cuyo «Guiso de alubias y pollo» apuntaba a esa receta seguía apuntando a algo que ya no existe. Esta tarea hace que **los planes reaccionen**, y con ella se cierra el nivel 6.

Había que resolver tres cosas:

1. **Qué dice el negocio**: qué le pasa a un plato cuya receta se ha borrado.
2. **Cómo escucha `MealPlanning` sin acoplarse al recetario.** El evento vive en `Recipes\Domain`, y desde HEXM-0025 la infraestructura de un contexto no puede usar el dominio de otro (el watchlist de HEXM-0026 lo había avisado).
3. **Cómo cambiar un plan**, que hasta ahora era completamente inmutable.

La idea de la solución:

- los platos **se desvinculan**: conservan todo menos el enlace;
- un **oyente** de `MealPlanning\Infrastructure` traduce el evento ajeno a un **caso de uso propio**, `UnlinkDeletedRecipe`, como un controlador traduce una petición HTTP;
- los **eventos son el contrato público** de un contexto: el test de arquitectura autoriza, en una lista explícita, solo `RecipeDeleted`;
- el plan cambia **devolviendo una versión nueva con el mismo id**, que el repositorio guarda en lugar de la anterior.

## Lógica de negocio

- **Regla 24: cuando se borra una receta, los platos que la usaban se desvinculan.** En el plan 27, la comida del domingo de la semana 1 («Guiso de alubias y pollo») apuntaba al guiso del recetario. Al borrar el guiso, el plato sigue diciendo «Guiso de alubias y pollo» y conserva sus ingredientes, pero ya no lleva al recetario. El usuario sigue sabiendo qué tiene que comer.
- **Solo cambian esos platos.** La ensalada de la cena del lunes, que apunta a otra receta, sigue enlazada. El número del plan, la fecha de inicio, las pautas fijas y los intercambios no cambian.
- **Un plan que no usaba la receta no se toca**: borrar una receta que no aparece en ningún plan no cambia nada.
- **No se impide borrar una receta que usan los planes.** El recetario es del usuario y puede borrar lo que quiera; los planes se adaptan. Impedirlo habría obligado al recetario a preguntar a los planes antes de borrar.
- **El borrado no espera a los planes.** La receta se borra primero y los planes se actualizan justo después. Si algo fallara al actualizarlos, la receta seguiría borrada y el plan seguiría apuntando a ella hasta repetir la desvinculación. Es un estado que ya estaba previsto: una referencia puede quedar colgando.

## Decisiones de arquitectura

### El recorrido completo

```
DELETE /api/recipes/{id}                                   ← Recipes (HEXM-0026)
  DeleteRecipe: la receta registra RecipeDeleted, se borra (COMMIT) y se publica
        │ EventBus → LaravelEventBus → dispatcher de Laravel (tras el commit)
        ▼
MealPlanning\Infrastructure\Recipes\RecipeDeletedListener   ← adaptador de ENTRADA
  handle(RecipeDeleted $event): solo pasa $event->recipeId() (texto)
        │
        ▼
MealPlanning\Application\UnlinkDeletedRecipe                ← caso de uso propio
  1. NutritionPlanRepository::usingRecipe(ref)     → los planes afectados
  2. $plan->withoutRecipe(ref)                     → plan nuevo, mismo id (dominio)
  3. NutritionPlanRepository::save(plan nuevo)     → reemplaza al anterior
```

### El oyente es un adaptador de entrada

Un controlador traduce una petición HTTP a un caso de uso; el oyente hace lo mismo con un evento:

| Entrada | Adaptador | Caso de uso |
|---|---|---|
| `POST /api/nutrition-plans` | `RegisterNutritionPlanController` | `RegisterNutritionPlan` |
| `RecipeDeleted` | `RecipeDeletedListener` | `UnlinkDeletedRecipe` |

- **Está en `MealPlanning\Infrastructure\Recipes`**, junto a la otra pieza que habla con el recetario (`RecipesContextRecipeCatalog`). Es la capa anticorrupción en las dos direcciones: hacia fuera para preguntar (HEXM-0025) y hacia dentro para reaccionar.
- **Del evento solo sale el id como texto.** El caso de uso habla el idioma de `MealPlanning` (`RecipeReference`) y no sabe que existe un evento: mañana lo podría llamar un comando de Artisan.
- **Lo registra `MealPlanningServiceProvider::boot()`**, con `listen(RecipeDeleted::class, RecipeDeletedListener::class)`. Es `MealPlanning` quien se apunta al evento; `Recipes` publica sin saber quién escucha.

### Los eventos, contrato público de un contexto (ADR 0019)

`RecipeDeleted` vive en `Recipes\Domain` porque lo registra el agregado (ADR 0018). El oyente lo tipa en `handle()`, así que lo usa. La regla «un contexto solo entra en otro por su capa de aplicación» lo prohibía.

- **Decisión**: un evento existe para que otros lo escuchen, así que es **la única pieza del dominio de un contexto que otro contexto puede usar**. El test de arquitectura lo descuenta con `ignoring(publicEvents())`, una lista **explícita**, hoy `['Recipes\Domain\RecipeDeleted']`. Publicar un evento para otros contextos pasa a ser una decisión (una línea en esa lista), no un efecto de crearlo.
- **Comprobado rompiéndolo**: sin la lista, el oyente hace fallar el test; con la lista, usar `RecipeId` desde el oyente también lo hace fallar. La excepción solo deja pasar el evento.

### El caso de uso y la consulta nueva

- **`UnlinkDeletedRecipe`** es un comando (no devuelve nada) e **idempotente**: un plan ya desvinculado no vuelve a aparecer, así que repetirlo no cambia nada. Es lo que permite arreglar una reacción que falló.
- **`NutritionPlanRepository::usingRecipe(RecipeReference)`** es una pregunta del negocio («¿qué planes usan esta receta?»), así que vive en el puerto y entra en sus tests de contrato:
  - **en memoria**, pregunta a cada plan (`NutritionPlan::usesRecipe()`): la regla de «qué es usar una receta» está en el agregado;
  - **en PostgreSQL**, consulta dentro del documento `weeks` con una ruta SQL/JSON:

    ```sql
    jsonb_path_exists(weeks, '$[*].days[*].* ? (@.recipe_id == $recipe)', jsonb_build_object('recipe', ?))
    ```

    Se lee «cada semana → cada día → cada plato cuyo `recipe_id` sea `$recipe`». El id va como **variable de la ruta**, igual que un `?` en SQL: nunca se pega en el texto de la consulta.

### Cambiar un agregado inmutable

`NutritionPlan` era `readonly` de arriba abajo, y sigue siéndolo. El primer cambio que pide el negocio no lo modifica: **devuelve uno nuevo con el mismo id**.

- `NutritionPlan::withoutRecipe()` → `MenuWeek::withoutRecipe()` → `MenuDay::withoutRecipe()` → `Dish::withoutRecipe()`. Cada nivel devuelve una copia con el cambio, y el plato que no apunta a esa receta se devuelve tal cual (el mismo objeto).
- **Sigue siendo el mismo plan** (misma identidad, `equals()` da `true`) en una **versión nueva**. Quien tenga la versión anterior no la ve cambiar «por detrás». El repositorio guarda la nueva en su lugar: `save()` ya reemplazaba un plan con el mismo id.
- **No vuelve a pasar por `create()`**: ninguna invariante del plan (lunes, dos semanas, una pauta por tipo) depende de las recetas de sus platos.

### Consistencia eventual y qué pasa si algo falla

- El borrado de la receta y la desvinculación son **dos transacciones**: la del recetario ya se ha confirmado cuando se publica el evento (`afterCommit()`, ADR 0018). Es **consistencia eventual** en su forma más simple: durante un instante, la receta ya no existe y el plan aún apunta a ella.
- El oyente se ejecuta **en la misma petición** que `DELETE`. Si la reacción lanzara una excepción, el cliente recibiría un 500 por un borrado que **sí ha ocurrido**. Por eso el oyente la **captura y la manda al `ExceptionHandler`** (los logs). El cliente recibe un 204, la receta está borrada y repetir `UnlinkDeletedRecipe` lo arregla. Un test lo demuestra con un repositorio de planes que falla.

## Alternativas descartadas

- **Un Laravel clásico**: una clave foránea `dishes.recipe_id → recipes.id` con `ON DELETE SET NULL`, o un *observer* `deleted` en el modelo `Recipe` que actualice los platos. La base de datos o el modelo del recetario harían el trabajo de los planes: `Recipes` conocería la estructura de `MealPlanning`, y la regla «se desvinculan» quedaría escondida en el esquema en lugar de estar en el dominio. Aquí además no hay tabla de platos, porque el plan es un documento (ADR 0013).
- **Impedir el borrado** (opción b): el recetario tendría que preguntar a `MealPlanning` antes de borrar, y los dos contextos dependerían el uno del otro.
- **Marcar el plato como «receta no disponible»** (opción c): un estado nuevo en `RecipeReference`, en el documento y en la API, sin que el negocio lo haya pedido.
- **Un evento de integración** (`Recipes\Application` traduce `RecipeDeleted` a otro evento para los demás contextos): mantiene la regla del test intacta, pero son dos clases para el mismo hecho, y una traducción que hoy no aporta nada.
- **Escuchar por el nombre de la clase** sin importarla: no rompe la regla, pero se pierden los tipos y la ayuda de PHPStan.
- **Llamar a `UnlinkDeletedRecipe` desde `DeleteRecipe`**: el recetario dependería de los planes.
- **Modificar el plan en el sitio** (quitar `readonly`): el plan podría cambiar «por detrás» a quien lo tuviera, y se perdería la garantía que da la inmutabilidad en todo el agregado.
- **Dejar que la excepción del oyente suba**: un 500 para un borrado que ha ocurrido confunde al cliente, que podría reintentar un borrado ya hecho.
- **Oyente en cola (`ShouldQueue`) con reintentos**: la solución robusta, pero exige un *worker* y una cola. Queda fuera de alcance (ADR 0019).

## Conceptos aplicados

| Concepto | Definición breve | Dónde verlo |
|---|---|---|
| Oyente como adaptador de entrada | Traduce un evento a un caso de uso, como un controlador traduce una petición. | `app-laravel/src/MealPlanning/Infrastructure/Recipes/RecipeDeletedListener.php` |
| Capa anticorrupción en la dirección de los eventos | Del evento ajeno solo entra el dato mínimo, en el idioma propio. | `RecipeDeletedListener::handle()` → `UnlinkDeletedRecipe` |
| Eventos como contrato público | El único elemento del dominio de un contexto que otro puede usar, en una lista explícita. | `app-laravel/tests/Architecture/HexagonalLayersTest.php` (`publicEvents()`) |
| Agregado inmutable que cambia con *withers* | Métodos de negocio que devuelven una versión nueva con la misma identidad. | `NutritionPlan::withoutRecipe()`, `Dish::withoutRecipe()` |
| Consistencia eventual | Dos agregados de dos contextos, dos transacciones y un estado intermedio previsto. | ADR 0019, `UnlinkDeletedRecipe` |
| Operación idempotente | Repetirla deja el mismo resultado; permite reparar un fallo. | `app-laravel/tests/Unit/MealPlanning/Application/UnlinkDeletedRecipeTest.php` |
| Consultas SQL/JSON *path* | Buscar dentro de un documento `jsonb` con una ruta y variables. | `EloquentNutritionPlanRepository::usingRecipe()` |
| Registro manual de oyentes | `Dispatcher::listen()` en el `boot()` del *provider* del contexto. | `MealPlanningServiceProvider::boot()` |
| Probar errores informados | `Exceptions::fake()` y `assertReported()` de Laravel. | `app-laravel/tests/Feature/MealPlanning/Infrastructure/Recipes/RecipeDeletedListenerTest.php` |

## Diagramas

- [Dos contextos, dos direcciones](../diagramas/HEXM-0027/dos-contextos-dos-direcciones.architecture.json) (`architecture`): ¿cómo se comunican `MealPlanning` y `Recipes` sin depender el uno del otro? Muestra las dos direcciones del nivel 6: preguntar con el puerto `RecipeCatalog` y su capa anticorrupción, y reaccionar a `RecipeDeleted` con el oyente y `UnlinkDeletedRecipe`. También el test de arquitectura, que solo autoriza ese evento.
- [Reaccionar al borrado](../diagramas/HEXM-0027/reaccion-al-borrado.sequence.json) (`sequence`): ¿qué pasa en los planes cuando se borra una receta? Muestra los tres caminos: el plan 27 desvincula sus platos, una receta sin uso no cambia nada, y si la reacción falla el borrado se mantiene y el error se registra.

Para verlos: `cd app-laravel && composer docs:diagrams` y abrir `docs/diagramas/HEXM-0027/dos-contextos-dos-direcciones.html` y `docs/diagramas/HEXM-0027/reaccion-al-borrado.html` en el navegador.
