<?php

declare(strict_types=1);

namespace MealPlanning\Domain;

use Shared\Domain\DomainException;

/**
 * InvalidDish: plato (o ingrediente de un plato) no válido.
 *
 * CAPA: Dominio (MealPlanning\Domain). Ver Shared\Domain\DomainException.
 */
final class InvalidDish extends DomainException
{
    public static function empty(): self
    {
        return new self('Un plato no puede estar vacío: debe tener un nombre o una descripción.');
    }

    public static function ingredientWithoutName(): self
    {
        return new self('Un ingrediente de un plato debe tener nombre.');
    }
}
