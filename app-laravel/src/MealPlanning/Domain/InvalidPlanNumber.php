<?php

declare(strict_types=1);

namespace MealPlanning\Domain;

use Shared\Domain\DomainException;

/**
 * InvalidPlanNumber: número de plan no válido.
 *
 * CAPA: Dominio (MealPlanning\Domain). Ver Shared\Domain\DomainException.
 */
final class InvalidPlanNumber extends DomainException
{
    public static function notPositive(int $value): self
    {
        return new self(sprintf('El número de plan debe ser mayor que cero (recibido: %d).', $value));
    }
}
