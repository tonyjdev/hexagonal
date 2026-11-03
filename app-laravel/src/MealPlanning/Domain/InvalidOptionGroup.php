<?php

declare(strict_types=1);

namespace MealPlanning\Domain;

use Shared\Domain\DomainException;

/**
 * InvalidOptionGroup: grupo "elige N" no válido (N fuera de rango, opción
 * vacía u opción repetida).
 *
 * CAPA: Dominio (MealPlanning\Domain). Ver Shared\Domain\DomainException.
 */
final class InvalidOptionGroup extends DomainException
{
    public static function chooseAtLeastOne(int $count): self
    {
        return new self(sprintf('En un grupo de opciones hay que elegir al menos una, no %d.', $count));
    }

    public static function moreThanAvailable(int $count, int $available): self
    {
        return new self(sprintf('No se pueden elegir %d opciones de un grupo que solo tiene %d.', $count, $available));
    }

    public static function duplicatedOption(string $option): self
    {
        return new self(sprintf('La opción "%s" está repetida en el grupo.', $option));
    }

    public static function emptyOption(): self
    {
        return new self('Una opción de un grupo no puede estar vacía.');
    }
}
