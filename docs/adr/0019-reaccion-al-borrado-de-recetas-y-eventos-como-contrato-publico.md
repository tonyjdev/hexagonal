# 0019. Reacción al borrado de recetas y eventos como contrato público

**Fecha:** 2026-11-05 · **Estado:** aceptada · **Tarea:** HEXM-0027 · **Relacionado:** [ADR 0010](0010-limites-del-agregado-nutritionplan-y-vigencia.md), [ADR 0013](0013-persistencia-del-agregado-nutritionplan-como-documento.md), [ADR 0016](0016-referencia-entre-contextos-y-evolucion-del-documento.md), [ADR 0017](0017-puerto-recipecatalog-y-capa-anticorrupcion.md), [ADR 0018](0018-eventos-de-dominio-y-su-publicacion.md)

## Contexto

Desde HEXM-0026 el recetario borra recetas y lo anuncia con `RecipeDeleted`. Los planes pueden tener platos que apuntan a esa receta (HEXM-0024), así que `MealPlanning` tiene que reaccionar, sin que `Recipes` sepa nada de los planes. Hay cuatro preguntas:

1. **La regla de negocio**: ¿qué pasa con un plato que apuntaba a una receta borrada? Las opciones eran:
   - **(a)** se desvincula;
   - **(b)** se impide el borrado;
   - **(c)** se marca como «receta no disponible».
2. **La frontera** (watchlist 271 de HEXM-0026): `RecipeDeleted` vive en `Recipes\Domain`, porque lo registra el agregado. El oyente de `MealPlanning` estará en su `Infrastructure`, y el test de arquitectura del ADR 0017 prohíbe que la infraestructura de un contexto use el dominio de otro. Las opciones eran:
   - tratar los eventos como contrato público;
   - un evento de integración publicado por `Recipes\Application`;
   - escuchar por el nombre de la clase, sin tipos.
3. **El primer cambio de un `NutritionPlan`**, que hasta ahora era inmutable (ADR 0010): ¿se modifica o se devuelve uno nuevo?
4. **Qué pasa si la reacción falla**, si el oyente se ejecuta en la misma petición que el borrado.

## Decisión

1. **Opción (a): se desvincula** (regla 24). El plato conserva su nombre, su descripción y sus ingredientes, y deja de apuntar a la receta. El plan sigue completo y válido. Es coherente con HEXM-0026, que ya permite borrar: (b) habría obligado al recetario a preguntar a los planes antes de borrar, y (c) añadía un estado nuevo a la referencia, al documento y a la API sin que el negocio lo pida.
2. **Los eventos de un contexto son su contrato público.**
   - El test de arquitectura «un contexto solo entra en otro por su capa de aplicación» descuenta, con `ignoring()`, una lista **explícita** de eventos: `publicEvents()`, hoy solo `Recipes\Domain\RecipeDeleted`. El resto de `Recipes\Domain` y toda `Recipes\Infrastructure` siguen prohibidos; se comprobó que el test falla si el oyente usa `RecipeId`.
   - Publicar un evento para otros contextos pasa a ser una decisión (una línea en la lista), no un efecto de crearlo.
   - Se descarta el evento de integración (dos clases para el mismo hecho y una traducción en `DeleteRecipe`) y escuchar por nombre (sin tipos ni ayuda de PHPStan).
3. **El oyente es un adaptador de entrada.**
   - `MealPlanning\Infrastructure\Recipes\RecipeDeletedListener` traduce el evento a un caso de uso propio, `MealPlanning\Application\UnlinkDeletedRecipe`, al que solo le pasa el id de la receta como texto. Es la capa anticorrupción en la dirección de los eventos: el núcleo de `MealPlanning` sigue sin conocer `Recipes`.
   - Lo registra `MealPlanningServiceProvider::boot()` en el *dispatcher* de Laravel: es `MealPlanning` quien se apunta al evento, no `Recipes` quien avisa a `MealPlanning`.
4. **`UnlinkDeletedRecipe`** busca los planes afectados con una consulta nueva del puerto, `NutritionPlanRepository::usingRecipe(RecipeReference)`, y guarda la versión nueva de cada uno.
   - Es un comando (no devuelve nada) e **idempotente**: un plan ya desvinculado no vuelve a aparecer.
   - En memoria, la consulta pregunta a cada plan (`NutritionPlan::usesRecipe()`).
   - En PostgreSQL usa una ruta SQL/JSON dentro del documento `weeks`: `jsonb_path_exists(weeks, '$[*].days[*].* ? (@.recipe_id == $recipe)', …)`, con el id como variable de la ruta, nunca concatenado. No lleva índice: si hiciera falta, uno GIN con `jsonb_path_ops`.
5. **El agregado cambia devolviendo una versión nueva.**
   - `NutritionPlan::withoutRecipe()` baja por el agregado (`MenuWeek`, `MenuDay` y `Dish::withoutRecipe()`, que ya eran inmutables) y devuelve **otro `NutritionPlan` con el mismo id**, en el que solo cambian esos platos.
   - No vuelve a pasar por `create()`, porque ninguna invariante del plan depende de las recetas de sus platos.
   - El plan de antes no cambia: quien lo tenga no lo ve cambiar «por detrás». El repositorio guarda la versión nueva en lugar de la anterior (mismo id).
6. **Si la reacción falla, se informa del error pero el borrado se mantiene.**
   - El oyente captura la excepción y la manda al `ExceptionHandler` de Laravel (logs). Si subiera, `DELETE /api/recipes/{id}` respondería 500 por un borrado que sí ha ocurrido y ya está confirmado (ADR 0018).
   - Los planes que no se hayan podido actualizar siguen apuntando a la receta borrada, un estado ya previsto (ADR 0016). Volver a lanzar `UnlinkDeletedRecipe` lo arregla.

## Consecuencias

- **A favor:**
  - Los dos contextos se comunican en las dos direcciones sin depender el uno del otro: `MealPlanning` pregunta con un puerto propio (ADR 0017) y escucha eventos con un adaptador de entrada. `Recipes` no conoce a `MealPlanning`.
  - La regla 24 está en el agregado y se prueba sin Laravel. El caso de uso se prueba con el repositorio en memoria, y el recorrido completo (borrado → evento → plan actualizado), de punta a punta.
  - El contrato entre contextos es explícito y comprobado: una lista de eventos públicos en el test de arquitectura.
- **En contra:**
  - **Consistencia eventual**: el borrado y la desvinculación son dos transacciones. Entre una y otra, y si la reacción falla, un plan puede apuntar a una receta borrada. Se acepta porque el estado ya estaba previsto y se arregla repitiendo el caso de uso.
  - **Entrega síncrona**: la reacción se ejecuta en la misma petición que el borrado y alarga su respuesta. Con muchos planes, o con más oyentes, habría que pasar a una cola (fuera de alcance).
  - Un fallo en la reacción solo deja rastro en los logs: nadie lo reintenta automáticamente.
- **Revisar cuando:**
  - haya más contextos que escuchen o la reacción sea lenta: oyentes en cola (`ShouldQueue`) con reintentos, y el patrón *outbox* del ADR 0018;
  - otro contexto publique eventos para los demás: se añaden a `publicEvents()`, con su nota en este ADR o uno nuevo.
