<?php

declare(strict_types=1);

namespace MealPlanning\Domain;

use Shared\Domain\DomainException;

/**
 * InvalidFixedMeal: pauta fija no válida (de comida o cena, o vacía).
 *
 * CAPA: Dominio (MealPlanning\Domain). Ver Shared\Domain\DomainException.
 */
final class InvalidFixedMeal extends DomainException
{
    public static function notAFixedMeal(MealType $mealType): self
    {
        return new self(sprintf(
            'La %s no es una pauta fija: cambia cada día y va en el menú.',
            $mealType->label(),
        ));
    }

    public static function empty(MealType $mealType): self
    {
        return new self(sprintf(
            'La pauta de %s no puede estar vacía: necesita una parte fija o algún grupo de opciones.',
            $mealType->label(),
        ));
    }

    /**
     * Un tipo de comida que no existe ("merienda", "brunch"...). Solo puede
     * pasar al traducir datos que llegan de fuera (el alta de un plan,
     * HEXM-0021): dentro del dominio, MealType es un enum cerrado.
     */
    public static function unknownMealType(string $mealType): self
    {
        return new self(sprintf(
            '"%s" no es un tipo de comida: las pautas fijas son el desayuno (breakfast), la media mañana (mid_morning) y la media tarde (afternoon_snack).',
            $mealType,
        ));
    }
}
