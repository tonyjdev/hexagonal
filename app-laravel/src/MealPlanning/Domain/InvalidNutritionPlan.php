<?php

declare(strict_types=1);

namespace MealPlanning\Domain;

use DateTimeImmutable;
use Shared\Domain\DomainException;

/**
 * InvalidNutritionPlan: plan nutricional no válido (semanas que faltan o se
 * repiten, fecha de inicio que no cae en lunes o pauta fija repetida).
 *
 * CAPA: Dominio (MealPlanning\Domain). Ver Shared\Domain\DomainException.
 */
final class InvalidNutritionPlan extends DomainException
{
    public static function missingWeek(int $number): self
    {
        return new self(sprintf(
            'Un plan tiene dos semanas que se alternan, la 1 y la 2: falta la semana %d.',
            $number,
        ));
    }

    public static function duplicatedWeek(int $number): self
    {
        return new self(sprintf('La semana %d aparece más de una vez en el plan.', $number));
    }

    public static function startsOnNonMonday(DateTimeImmutable $startsOn): self
    {
        // Formato de fecha español (día/mes/año) sin depender de la extensión
        // intl: el núcleo solo usa PHP nativo (ADR 0004).
        return new self(sprintf(
            'Un plan empieza siempre en lunes, y el %s es %s.',
            $startsOn->format('d/m/Y'),
            Weekday::fromDate($startsOn)->label(),
        ));
    }

    public static function duplicatedFixedMeal(MealType $mealType): self
    {
        return new self(sprintf('La pauta de %s aparece más de una vez en el plan.', $mealType->label()));
    }
}
