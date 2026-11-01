<?php

declare(strict_types=1);

namespace Recipes\Domain;

use Shared\Domain\DomainException;

/**
 * InvalidStep: paso de preparación no válido.
 *
 * CAPA: Dominio (Recipes\Domain). Ver Shared\Domain\DomainException.
 */
final class InvalidStep extends DomainException
{
    public static function empty(): self
    {
        return new self('Un paso de preparación no puede estar vacío.');
    }
}
