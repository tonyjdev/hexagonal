<?php

declare(strict_types=1);

namespace MealPlanning\Domain;

use Shared\Domain\DomainException;

/**
 * InvalidPlanImportId: el identificador recibido no es un UUID válido.
 *
 * CAPA: Dominio (MealPlanning\Domain). Ver Shared\Domain\DomainException.
 */
final class InvalidPlanImportId extends DomainException
{
    public static function malformed(string $value): self
    {
        return new self(sprintf('"%s" no es un identificador de importación válido.', $value));
    }
}
