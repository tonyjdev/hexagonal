<?php

declare(strict_types=1);

namespace Recipes\Infrastructure\Http;

use Recipes\Application\GetRecipe;

/**
 * GetRecipeController: GET /api/recipes/{id} consulta una receta.
 *
 * CONCEPTO: adaptador driving de una consulta
 * Mismo papel que CreateRecipeController, pero para leer: traduce la
 * petición HTTP (el id de la URL) a una llamada al caso de uso, y su
 * resultado (un RecipeView) a JSON con RecipeResource. Tres piezas, tres
 * responsabilidades:
 *
 *   GetRecipeController  → traducir HTTP ↔ caso de uso
 *   GetRecipe            → orquestar la consulta (y decir "no existe")
 *   RecipeResource       → decidir el formato JSON
 *
 * Sin FormRequest: no hay cuerpo que validar, y la forma del id la valida
 * el dominio (RecipeId::fromString → InvalidRecipeId → 422).
 * Sin try/catch: RecipeNotFound sube y el manejador global la traduce a 404.
 *
 * Devolver un JsonResource desde el controlador basta: Laravel lo convierte
 * en una respuesta 200 con su JSON.
 *
 * CAPA: Infraestructura, adaptador de entrada HTTP (Recipes\Infrastructure\Http).
 */
final readonly class GetRecipeController
{
    public function __construct(private GetRecipe $getRecipe) {}

    public function __invoke(string $id): RecipeResource
    {
        return new RecipeResource(($this->getRecipe)($id));
    }
}
