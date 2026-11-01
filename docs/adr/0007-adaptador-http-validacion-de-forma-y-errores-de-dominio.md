# 0007. Adaptador HTTP: validación de forma en la frontera y errores de dominio en un único manejador

**Fecha:** 2026-11-01 · **Estado:** aceptada · **Tarea:** HEXM-0010 · **Relacionado:** [ADR 0003](0003-arquitectura-hexagonal-en-src.md), [ADR 0004](0004-lista-blanca-de-dependencias-en-el-nucleo.md), [ADR 0006](0006-persistencia-del-agregado-recipe.md)

## Contexto

Con `POST /api/recipes` entra en el proyecto el primer adaptador *driving* (de entrada). Es el primero de muchos controladores, así que las decisiones que se toman aquí fijan el patrón de los siguientes. Había que decidir:

1. **Qué valida la capa HTTP y qué valida el dominio.** En un Laravel clásico, todas las reglas (campos obligatorios, `min:1`, `in:gram,jar…`) van en la validación del controlador o de una `FormRequest`. Aquí las reglas de negocio ya viven en el dominio (`Servings`, `Quantity`, `Unit`, `Recipe`).
2. **Cómo llegan al cliente los errores de dominio** (`InvalidRecipe`, `InvalidQuantity`…), que el núcleo lanza sin saber nada de HTTP.
3. **Dónde se enchufa el puerto `RecipeRepository` a su adaptador de Eloquent**, para que el contenedor de Laravel pueda construir `CreateRecipe`.
4. **Dónde viven los controladores**: en `app/Http` (lo habitual en Laravel) o en el contexto.

Condición de partida: `CreateRecipe` no cambia (criterio de aceptación de HEXM-0010).

## Decisión

1. **La frontera valida la forma; el dominio, las reglas.**
   - La `FormRequest` (`CreateRecipeRequest`) comprueba solo el contrato de la API: que los campos existan y tengan el tipo esperado (`servings` entero, `ingredients` lista, `amount` texto…). Si falla, Laravel responde `422` con `{"message", "errors"}` y el controlador no llega a ejecutarse.
   - No repite ninguna regla de negocio: no hay `min:1` en `servings`, ni `in:` en `unit`, ni `required` en `ingredients` (se usa `present`, que admite la lista vacía). Esas peticiones bien formadas llegan al dominio, que las rechaza con su mensaje en español.
   - La `FormRequest` traduce la petición al comando (`toCommand()`). Deshace el `ConvertEmptyStringsToNull` de Laravel en los textos (`null` → `""`) para que un nombre o un paso vacío lo rechace el dominio, y no un error de tipo.
2. **Un único manejador traduce `Shared\Domain\DomainException` a `422 {"message": "…"}`**, en `withExceptions()` de `bootstrap/app.php`.
   - Se aplica a las peticiones que esperan JSON (`/api/*` o con `Accept: application/json`). Para el resto se deja el tratamiento por defecto de Laravel, hasta que haya un frontend.
   - Los errores de dominio no se registran en el log (`dontReport`): son errores esperados del usuario.
   - Los controladores no llevan `try/catch`. Cualquier otra excepción sigue siendo un `500`.
3. **Un service provider por *bounded context*** (`Recipes\Infrastructure\RecipesServiceProvider`) hace de raíz de composición: `bind(RecipeRepository, EloquentRecipeRepository)`.
   - Se usa `bind` y no `singleton`, porque es la opción segura si el adaptador llega a tener estado (por ejemplo, con Octane).
   - El caso de uso lo resuelve el *autowiring*.
4. **Los controladores y las `FormRequest` viven en la capa `Infrastructure` de su contexto** (`Recipes\Infrastructure\Http`), no en `app/Http`.
   - Son invocables (una acción por clase), como los casos de uso.
   - Dos tests de arquitectura nuevos exigen que `Illuminate\Http`, `Illuminate\Foundation\Http`, `Illuminate\Routing` e `Illuminate\Support\ServiceProvider` solo se usen en `App` o en las capas `Infrastructure`.
5. **Las rutas de API se registran a mano** (`routes/api.php` + `api:` en `withRouting()`), sin `php artisan install:api`, que instalaría Sanctum (autenticación, fuera del alcance).

## Consecuencias

- **A favor:**
  - Cada regla de negocio se escribe una vez, en el dominio, y protege a todos los adaptadores de entrada presentes y futuros (Artisan, el importador de Python…).
  - El cliente distingue los dos tipos de error por la forma de la respuesta: con `errors` (petición mal formada) o solo con `message` (regla de negocio).
  - Una excepción de dominio nueva, de cualquier contexto, queda traducida a `422` sin tocar el manejador.
  - Los controladores son triviales (traducir y delegar), y cambiar el contrato de la API solo afecta a la `FormRequest`.
- **En contra:**
  - Una petición puede fallar en dos momentos distintos: primero la forma, después las reglas. El cliente no recibe todos los errores a la vez. Por ejemplo, un `servings` de tipo incorrecto y un ingrediente repetido se descubren en dos peticiones.
  - Los errores de dominio llegan de uno en uno (el primero que se incumple), sin indicar el campo.
  - Los mensajes de validación de Laravel salen en inglés porque no hay traducciones (`lang/es`). Está registrado como `tech_debt` de HEXM-0010.
  - La cabecera `Location` apunta a `GET /api/recipes/{id}`, que no existe hasta HEXM-0011.
- **Revisar cuando:**
  - Haya un frontend web, para decidir cómo se muestran ahí los errores de dominio.
  - Un cliente necesite saber a qué campo se refiere un error de dominio. Entonces se podría añadir un código de error estable a `DomainException`.
