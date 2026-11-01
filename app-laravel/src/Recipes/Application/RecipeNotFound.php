<?php

declare(strict_types=1);

namespace Recipes\Application;

use Recipes\Domain\RecipeId;
use Shared\Application\NotFound;

/**
 * RecipeNotFound: no existe ninguna receta con ese identificador.
 *
 * La lanza GetRecipe cuando el repositorio devuelve null. Ver NotFound para
 * el porqué de su capa y de su clase base (se traduce a HTTP 404, no a 422).
 *
 * CAPA: Aplicación (Recipes\Application).
 */
final class RecipeNotFound extends NotFound
{
    public static function withId(RecipeId $id): self
    {
        return new self(sprintf('No existe ninguna receta con el identificador «%s».', $id->value()));
    }
}
