# 0008. Consultas: DTO de lectura, "no encontrado" como 404 e id mal formado como 422

**Fecha:** 2026-11-01 · **Estado:** aceptada · **Tarea:** HEXM-0011 · **Relacionado:** [ADR 0005](0005-identificadores-uuid-v7-en-el-dominio.md), [ADR 0007](0007-adaptador-http-validacion-de-forma-y-errores-de-dominio.md)

## Contexto

Con `GET /api/recipes/{id}` aparece el primer caso de uso de **consulta** (`GetRecipe`). Es el patrón que seguirán las demás lecturas (planes, "¿qué me toca hoy?"), y la tarea dejaba abiertas varias decisiones:

1. **Qué devuelve una consulta:** la entidad `Recipe` o un objeto pensado para mostrar.
2. **Dónde se convierte la fracción exacta en texto legible** (3/2 → "1 ½"): en el dominio, en la aplicación o en el adaptador HTTP.
3. **Dónde vive y cómo se traduce el "no encontrado".**
4. **Qué responde un id mal formado** (`/api/recipes/abc`): `404` o `422`.
5. **Cómo se construye el JSON:** con un *API Resource* de Laravel o serializando el DTO.

Las cinco las decidió el usuario a partir de una recomendación razonada.

## Decisión

1. **Las consultas devuelven un DTO de lectura, nunca la entidad.**
   - `GetRecipe` devuelve un `RecipeView` (con `IngredientView`) con primitivos ya calculados.
   - Se construye con `RecipeView::fromRecipe()`, en la capa de aplicación.
   - Es CQS, no CQRS: la consulta reutiliza el puerto `RecipeRepository::find()` y el mismo modelo.
2. **El texto legible de una cantidad lo calcula el dominio:** `Quantity::amountAsText()`, el inverso de `fromString()`.
   - Usa fracciones Unicode solo si `fromString()` sabe leerlas, y si no, "n/d".
   - Un test de ida y vuelta garantiza que todo lo que se escribe se vuelve a leer igual.
3. **"No encontrado" es un resultado de la consulta y vive en la aplicación.**
   - `Recipes\Application\RecipeNotFound` extiende la base `Shared\Application\NotFound` (`\RuntimeException`), no `DomainException`.
   - Un único manejador en `bootstrap/app.php` traduce cualquier `NotFound` a `404 {"message"}` en español, sin reportarlo al log.
4. **Un id mal formado responde `422`** con el mensaje del dominio (`InvalidRecipeId`).
   - No necesita código: `RecipeId::fromString()` ya lo rechaza y el manejador de `DomainException` lo traduce.
   - La ruta no lleva `whereUuid()`: con la restricción, Laravel respondería un `404` en inglés antes de llegar al controlador. Un test lo vigila.
5. **El JSON lo construye un *API Resource*** (`RecipeResource`, en `Recipes\Infrastructure\Http`) que envuelve el DTO.
   - Aplica la convención de Laravel: claves en `snake_case` y envoltorio `{"data": …}`.
   - `amount` y `unit` tienen la misma forma que en el `POST`.
   - La ruta tiene nombre (`recipes.show`), y la cabecera `Location` del alta se genera con `route()`.

## Consecuencias

- **A favor:**
  - El agregado no sale de la aplicación: ningún adaptador puede cambiar una receta sin un caso de uso.
  - El formato "1 ½" lo reutilizarán otros adaptadores, como la lista de la compra del bloque C.
  - El cliente distingue tres errores: `422` con `errors` (forma), `422` solo con `message` (negocio o id imposible) y `404` (no existe).
  - Un "no encontrado" de otro contexto (`PlanNotFound`, nivel 5) queda traducido sin tocar `bootstrap/app.php`.
- **En contra:**
  - La consulta reconstruye el agregado completo (y vuelve a comprobar sus invariantes) solo para mostrarlo. Hoy el coste es despreciable.
  - La cantidad no recuerda cómo se escribió: "0,3" vuelve como "3/10".
  - El `GET` responde con `{"data": …}` y el `POST` con `{"id": …}`, sin envoltorio.
  - Un id mal formado y uno inexistente dan códigos distintos. Es intencionado, pero algunos clientes REST esperan `404` en ambos casos.
- **Revisar cuando:**
  - Las lecturas necesiten listados, búsquedas o rendimiento. Entonces se valorará un puerto de lectura propio (CQRS) que devuelva la vista sin reconstruir el agregado.
  - Haya clientes que necesiten la fracción en bruto. Entonces se podrían añadir `numerator` y `denominator` a la vista.
