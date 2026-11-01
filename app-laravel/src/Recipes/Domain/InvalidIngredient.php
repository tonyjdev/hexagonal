<?php

declare(strict_types=1);

namespace Recipes\Domain;

use Shared\Domain\DomainException;

/**
 * InvalidIngredient: ingrediente no válido.
 *
 * CAPA: Dominio (Recipes\Domain). Ver Shared\Domain\DomainException.
 */
final class InvalidIngredient extends DomainException
{
    public static function emptyName(): self
    {
        return new self('Un ingrediente debe tener nombre.');
    }
}
