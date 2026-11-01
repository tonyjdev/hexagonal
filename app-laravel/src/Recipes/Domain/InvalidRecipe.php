<?php

declare(strict_types=1);

namespace Recipes\Domain;

use Shared\Domain\DomainException;

/**
 * InvalidRecipe: se ha intentado dejar una receta en un estado que incumple
 * una de sus invariantes (ver Recipe y las reglas de negocio de
 * docs/DOMINIO.md).
 *
 * CAPA: Dominio (Recipes\Domain). Ver Shared\Domain\DomainException.
 */
final class InvalidRecipe extends DomainException
{
    public static function emptyName(): self
    {
        return new self('Una receta debe tener nombre.');
    }

    public static function withoutIngredients(): self
    {
        return new self('Una receta debe tener al menos un ingrediente.');
    }

    public static function duplicatedIngredient(string $name): self
    {
        return new self(sprintf('El ingrediente «%s» está repetido.', $name));
    }
}
