<?php

declare(strict_types=1);

namespace MealPlanning\Domain;

use Shared\Domain\DomainException;

/**
 * InvalidRecipeReference: la referencia a una receta no es un UUID válido.
 *
 * Es el error de MealPlanning, con su propio mensaje, aunque Recipes tenga
 * uno parecido (InvalidRecipeId): cada contexto habla su idioma.
 *
 * CAPA: Dominio (MealPlanning\Domain). Ver Shared\Domain\DomainException.
 */
final class InvalidRecipeReference extends DomainException
{
    public static function malformed(string $value): self
    {
        return new self(sprintf('"%s" no es una referencia de receta válida.', $value));
    }
}
