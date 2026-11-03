<?php

declare(strict_types=1);

namespace MealPlanning\Domain;

use Shared\Domain\DomainException;

/**
 * InvalidMenuDay: día de menú no válido (le falta la comida o la cena, o cae
 * en el día libre).
 *
 * CAPA: Dominio (MealPlanning\Domain). Ver Shared\Domain\DomainException.
 */
final class InvalidMenuDay extends DomainException
{
    public static function withoutMeal(Weekday $weekday, MealType $meal): self
    {
        return new self(sprintf('Al menú del %s le falta la %s.', $weekday->label(), $meal->label()));
    }

    public static function onFreeDay(Weekday $weekday): self
    {
        return new self(sprintf('El %s es día libre: no puede tener menú.', $weekday->label()));
    }
}
