# Recipes (Recetario)

Contexto que gestiona **recetas**: nombre, raciones, ingredientes con su cantidad y pasos de preparación. No sabe nada de planes, semanas ni calendarios.

| Capa | Qué irá aquí (nivel 1-2 del roadmap) |
|---|---|
| `Domain/` | `Recipe` (entidad), `RecipeId`, `Ingredient`, `Quantity`, `Unit`, `Step` y `Servings` (value objects), `RecipeDeleted` (evento) y el puerto `RecipeRepository` (interfaz). |
| `Application/` | Casos de uso: `CreateRecipe` (comando, con su `CreateRecipeCommand`) y `GetRecipe` (consulta, que devuelve el DTO de lectura `RecipeView` o lanza `RecipeNotFound`); más adelante `DeleteRecipe`… Orquestan el dominio a través de sus puertos. |
| `Infrastructure/` | Adaptadores: `Persistence/Eloquent/` (`EloquentRecipeRepository`, sus modelos y su *mapper*) e `InMemoryRecipeRepository` para los tests; `Http/` con los controladores de `/api/recipes` (`CreateRecipeController` y su `CreateRecipeRequest`; `GetRecipeController` y su `RecipeResource`), la página web `RecipePageController` y sus vistas Blade en `Http/views/` (namespace `recipes::`), y `RecipesServiceProvider`, que conecta cada puerto con su adaptador. |

Otros contextos solo conocen una receta por su `RecipeId`.
