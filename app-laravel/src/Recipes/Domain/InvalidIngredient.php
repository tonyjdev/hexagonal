<?php

declare(strict_types=1);

namespace Recipes\Domain;

use Shared\Domain\DomainException;

/**
 * InvalidIngredient: ingrediente no válido.
 *
 * CAPA: Dominio (Recipes\Domain). Ver Shared\Domain\DomainException.
 */
final class InvalidIngredient extends DomainException
{
    public static function emptyName(): self
    {
        return new self('Un ingrediente debe tener nombre.');
    }

    public static function unknownUnit(string $unit): self
    {
        return new self(sprintf('La unidad "%s" no existe.', $unit));
    }

    /**
     * Cantidad sin unidad ("200") o unidad sin cantidad ("gram"). Un
     * ingrediente lleva las dos o ninguna ("al gusto").
     */
    public static function incompleteQuantity(string $name): self
    {
        return new self(sprintf('El ingrediente «%s» debe indicar la cantidad y la unidad, o ninguna de las dos si es al gusto.', $name));
    }
}
