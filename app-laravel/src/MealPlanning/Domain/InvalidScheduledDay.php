<?php

declare(strict_types=1);

namespace MealPlanning\Domain;

use DateTimeImmutable;
use Shared\Domain\DomainException;

/**
 * InvalidScheduledDay: no se puede programar ese día (la fecha es anterior
 * al inicio del plan, o se pide como libre un día que no lo es).
 *
 * CAPA: Dominio (MealPlanning\Domain). Ver Shared\Domain\DomainException.
 */
final class InvalidScheduledDay extends DomainException
{
    public static function beforePlanStarts(DateTimeImmutable $date, NutritionPlan $plan): self
    {
        return new self(sprintf(
            'El plan %d empieza el %s: el %s no tiene menú en este plan.',
            $plan->number()->value(),
            $plan->startsOn()->format('d/m/Y'),
            $date->format('d/m/Y'),
        ));
    }

    public static function notAFreeDay(Weekday $weekday): self
    {
        return new self(sprintf('El %s no es día libre: tiene menú.', $weekday->label()));
    }
}
