# Nivel 06: Relación con recetas

**Fecha:** 2026-11-05 · **Etiqueta git:** `nivel-06`

Tareas: HEXM-0024 (referencia a receta en un plato), HEXM-0025 (validar la receta entre contextos), HEXM-0026 (borrar una receta y publicar `RecipeDeleted`) y HEXM-0027 (`MealPlanning` reacciona al borrado de recetas).

## Qué se ha construido

- **Los dos contextos, conectados sin depender el uno del otro.** Al empezar el nivel, el recetario y los planes eran dos mundos sin relación. Al terminarlo, un plato puede apuntar a una receta, el alta comprueba que la receta exista, el recetario puede borrar recetas y anunciarlo, y los planes reaccionan desvinculando sus platos. Y aun así, ni `Recipes` conoce `MealPlanning`, ni el núcleo de `MealPlanning` conoce `Recipes`.
- **La referencia** (HEXM-0024): `RecipeReference`, un *value object* propio de `MealPlanning` sobre el `Uuid` del *shared kernel*, no el `RecipeId` del recetario.
  - `Dish::withRecipe()` añade la referencia sin dejar de ser inmutable.
  - `recipe_id` entra en el documento `weeks` con lectura compatible de los planes antiguos, sin migrar datos.
- **La pregunta al otro contexto** (HEXM-0025): el puerto propio `RecipeCatalog` (en `Application`) y la capa anticorrupción `RecipesContextRecipeCatalog` sobre `Recipes\Application\GetRecipe`.
  - Si una receta no existe, `UnknownRecipe` (422).
  - Un test de arquitectura fija que un contexto solo entra en otro por su capa de aplicación.
- **El primer evento de dominio** (HEXM-0026): `DELETE /api/recipes/{id}`.
  - La receta registra `RecipeDeleted` (*trait* `RecordsEvents`) y `DeleteRecipe` lo publica por el puerto `Shared\Application\EventBus` después de borrar.
  - `LaravelEventBus` usa `afterCommit()`: nunca anuncia algo que se deshace.
- **La reacción** (HEXM-0027): el oyente `RecipeDeletedListener` (un adaptador de entrada) traduce el evento al caso de uso propio `UnlinkDeletedRecipe`.
  - El caso de uso busca los planes con `usingRecipe()` (SQL/JSON sobre `jsonb`) y guarda la versión nueva de cada uno: `NutritionPlan::withoutRecipe()`, con el mismo id.
  - Los eventos son el contrato público de un contexto (`publicEvents()`).
- **564 tests** en el gate (eran 480 al cerrar el nivel 5).

```
                  pregunta (síncrona, ADR 0017)
 MealPlanning ── RecipeCatalog ──► RecipesContextRecipeCatalog ──► Recipes\Application\GetRecipe
      ▲
      │ reacciona (evento, ADR 0018 y 0019)
 RecipeDeletedListener ◄── dispatcher ◄── LaravelEventBus ◄── DeleteRecipe ◄── DELETE /api/recipes/{id}
      │                                   (afterCommit)        (Recipe registra RecipeDeleted)
      ▼
 UnlinkDeletedRecipe ─► usingRecipe() ─► plan->withoutRecipe() ─► save()
```

## Conceptos aprendidos

- **Referencias entre *bounded contexts* por identidad**: un tipo propio en cada contexto, y solo el valor del UUID en común.
- **Capa anticorrupción en las dos direcciones.** Hacia fuera, un puerto propio cuyo adaptador traduce la pregunta al otro contexto. Hacia dentro, un oyente que traduce un evento ajeno a un caso de uso propio.
- **Tests de arquitectura que fijan *por dónde* se cruza una frontera**, no solo *si* se cruza. Con una excepción explícita y estrecha (`publicEvents()`) que se comprobó rompiéndola.
- **Evento de dominio**: un hecho del pasado, inmutable, con nombre del negocio, que el emisor publica sin conocer a los receptores.
- **El agregado registra sus eventos y la aplicación los publica.**
- **Antes o después del *commit***, y qué se arriesga en cada caso. Publicar después con `afterCommit()` y aceptar que un aviso se pueda perder; el patrón *outbox* es el remedio completo, que queda pendiente.
- **Consistencia eventual**: el borrado y la reacción son dos transacciones, con un estado intermedio previsto y una operación idempotente para repararlo.
- **Cambiar un agregado inmutable**: *withers* que bajan por el agregado y devuelven una versión nueva con la misma identidad.
- **Evolución compatible de un documento `jsonb`** (clave ausente = `null`) y **consultas SQL/JSON** dentro de él.
- **Contratos de API que crecen sin romperse**: claves opcionales nuevas, como las claves hermanas `lunch_recipe_id` de la semana 1.

## Decisiones tomadas (y por qué)

- **`RecipeReference` propio y no `RecipeId` compartido**: los contextos comparten la técnica, no el concepto ([ADR 0016](../adr/0016-referencia-entre-contextos-y-evolucion-del-documento.md)).
- **El puerto `RecipeCatalog` en `Application`**, devolviendo un `bool`, y la capa anticorrupción sobre `GetRecipe` sin tocar el recetario ([ADR 0017](../adr/0017-puerto-recipecatalog-y-capa-anticorrupcion.md)).
- **Eventos**: el agregado los crea; el puerto `EventBus` va en `Shared\Application` sobre el *dispatcher* de Laravel; se publican después del *commit*, con datos primitivos y el día del `Clock` ([ADR 0018](../adr/0018-eventos-de-dominio-y-su-publicacion.md)).
- **Regla 24: los platos se desvinculan** al borrar su receta, en lugar de impedir el borrado. Los eventos son contrato público. Si la reacción falla, el borrado se mantiene y el error se registra ([ADR 0019](../adr/0019-reaccion-al-borrado-de-recetas-y-eventos-como-contrato-publico.md)).

## Dificultades / dudas

- **El evento del dominio frente a la frontera** (HEXM-0026 → HEXM-0027). Al decidir que el agregado crea el evento, `RecipeDeleted` quedó en `Recipes\Domain`, justo donde la regla de HEXM-0025 impedía entrar. Se detectó antes de escribir el oyente, se registró como `watchlist` y se resolvió con una decisión explícita. Es un buen ejemplo de una regla de arquitectura que obliga a pensar antes de improvisar.
- **Pest no ejecuta un valor de *dataset* si el parámetro está tipado `Closure`** (HEXM-0025): la doble *closure* que esperaba no hacía falta.
- **El contrato de la semana 1 no tenía dónde llevar una receta**, porque el plato es un texto. Se eligieron claves hermanas, compatibles con lo que ya existía.
- **Pendiente:**
  - el patrón *outbox* y los oyentes en cola, si perder un aviso o la entrega síncrona dejan de ser aceptables (ADR 0018 y 0019);
  - un «botón de reintentar» para la desvinculación (por ejemplo, un comando de Artisan);
  - los mensajes de validación de forma en inglés (`tech_debt` de HEXM-0022);
  - el siguiente bloque: Python y FastAPI con el importador de la semana 1 (nivel 7).

## Para profundizar

- Eric Evans, *Domain-Driven Design*, capítulo 14 (*Maintaining Model Integrity*): *context map*, *anticorruption layer* y *shared kernel*.
- Vaughn Vernon, *Implementing Domain-Driven Design*, capítulos 8 (*Domain Events*) y 13 (*Integrating Bounded Contexts*).
- Chris Richardson, *Transactional outbox* (microservices.io).
- La documentación de PostgreSQL sobre SQL/JSON *path* (`jsonb_path_exists`) y los índices GIN con `jsonb_path_ops`.
- La documentación de Laravel sobre eventos y oyentes, `afterCommit()` y `Exceptions::fake()`.
