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
}
