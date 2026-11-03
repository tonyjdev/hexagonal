<?php

declare(strict_types=1);

namespace MealPlanning\Domain;

use Shared\Domain\DomainException;

/**
 * InvalidExchangeTable: tabla de intercambios no válida (un intercambio sin
 * alimento o sin ración, o una regla vacía).
 *
 * Una sola excepción para Exchange y ExchangeTable, como InvalidDish cubre
 * Dish y DishIngredient: el usuario lo vive como "la tabla está mal".
 *
 * CAPA: Dominio (MealPlanning\Domain). Ver Shared\Domain\DomainException.
 */
final class InvalidExchangeTable extends DomainException
{
    public static function exchangeWithoutFood(ExchangeCategory $category): self
    {
        return new self(sprintf('Un intercambio de %s debe indicar el alimento.', $category->label()));
    }

    public static function exchangeWithoutServing(string $food): self
    {
        return new self(sprintf('El intercambio "%s" debe indicar la ración.', $food));
    }

    public static function emptyRule(): self
    {
        return new self('Una regla de los intercambios no puede estar vacía.');
    }
}
