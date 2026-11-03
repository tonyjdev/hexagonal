<?php

declare(strict_types=1);

namespace Recipes\Infrastructure\Http;

use Illuminate\Http\Response;
use Recipes\Application\DeleteRecipe;

/**
 * DeleteRecipeController: DELETE /api/recipes/{id} borra una receta.
 *
 * CONCEPTO: adaptador driving de un comando sin respuesta (HEXM-0026)
 * Como GetRecipeController: traduce el id de la URL a una llamada al caso de
 * uso, sin FormRequest (no hay cuerpo) y sin try/catch. Los tres resultados
 * salen solos:
 *   - borrada              → 204 No Content (no hay nada que devolver);
 *   - RecipeNotFound       → 404, por el manejador global (NotFound);
 *   - id mal formado       → 422, por el manejador global (DomainException).
 *
 * El controlador no sabe nada del evento RecipeDeleted: lo registra la
 * receta y lo publica el caso de uso. Si mañana se borra una receta desde un
 * comando de Artisan, se publica igual.
 *
 * CAPA: Infraestructura, adaptador de entrada HTTP (Recipes\Infrastructure\Http).
 */
final readonly class DeleteRecipeController
{
    public function __construct(private DeleteRecipe $deleteRecipe) {}

    public function __invoke(string $id): Response
    {
        ($this->deleteRecipe)($id);

        // 204: éxito sin cuerpo. Es la respuesta habitual de un DELETE en
        // REST (un 200 obligaría a inventar un cuerpo).
        return new Response(status: 204);
    }
}
