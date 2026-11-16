<?php

declare(strict_types=1);

namespace MealPlanning\Domain;

use Shared\Domain\DomainException;

/**
 * InvalidPlanImport: una transición que la importación de un plan no admite
 * (confirmarla sin la semana 2, o cambiarla una vez confirmada).
 *
 * CAPA: Dominio (MealPlanning\Domain). Ver Shared\Domain\DomainException.
 */
final class InvalidPlanImport extends DomainException
{
    public static function missingSelfWeek(PlanNumber $number): self
    {
        return new self(sprintf(
            'La importación del plan %d no se puede confirmar: falta el Excel de la semana 2.',
            $number->value(),
        ));
    }

    public static function alreadyConfirmed(PlanNumber $number): self
    {
        return new self(sprintf(
            'La importación del plan %d ya está confirmada: no se puede cambiar ni volver a confirmar.',
            $number->value(),
        ));
    }

    public static function confirmedWithoutSelfWeek(PlanNumber $number): self
    {
        return new self(sprintf(
            'La importación del plan %d no puede estar confirmada sin la semana 2.',
            $number->value(),
        ));
    }
}
