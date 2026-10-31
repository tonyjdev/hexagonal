# Recipes (Recetario)

Contexto que gestiona **recetas**: nombre, raciones, ingredientes con su cantidad y pasos de preparación. No sabe nada de planes, semanas ni calendarios.

| Capa | Qué irá aquí (nivel 1-2 del roadmap) |
|---|---|
| `Domain/` | `Recipe` (entidad), `RecipeId`, `Ingredient`, `Quantity`, `Unit`, `Step` y `Servings` (value objects), `RecipeDeleted` (evento) y el puerto `RecipeRepository` (interfaz). |
| `Application/` | Casos de uso: `CreateRecipe`, `GetRecipe`, `DeleteRecipe`… Orquestan el dominio a través de sus puertos. |
| `Infrastructure/` | Adaptadores: `EloquentRecipeRepository` (y `InMemoryRecipeRepository` para los tests), controladores HTTP de `/api/recipes` y el service provider que conecta cada puerto con su adaptador. |

Otros contextos solo conocen una receta por su `RecipeId`.
