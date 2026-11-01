<?php

declare(strict_types=1);

namespace Recipes\Domain;

use Shared\Domain\DomainException;

/**
 * InvalidServings: número de raciones no válido.
 *
 * CAPA: Dominio (Recipes\Domain). Ver Shared\Domain\DomainException.
 */
final class InvalidServings extends DomainException
{
    public static function notPositive(): self
    {
        return new self('Una receta debe tener al menos una ración.');
    }
}
