<?php

declare(strict_types=1);

namespace Recipes\Domain;

use Shared\Domain\DomainException;

/**
 * InvalidRecipeId: el identificador recibido no es un UUID válido.
 *
 * CAPA: Dominio (Recipes\Domain). Ver Shared\Domain\DomainException.
 */
final class InvalidRecipeId extends DomainException
{
    public static function malformed(string $value): self
    {
        return new self(sprintf('"%s" no es un identificador de receta válido.', $value));
    }
}
