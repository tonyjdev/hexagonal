<?php

declare(strict_types=1);

namespace Recipes\Domain;

use Shared\Domain\DomainException;

/**
 * InvalidQuantity: se ha intentado crear una Quantity que el negocio no admite.
 *
 * CONCEPTO: constructores con nombre en las excepciones
 * En lugar de `new InvalidQuantity('...')` repartido por el código, cada
 * motivo de error tiene su propio método estático. El mensaje (en español,
 * para el usuario final) se escribe en un único sitio y quien lanza la
 * excepción dice QUÉ regla se ha roto, no cómo se redacta.
 *
 * CAPA: Dominio (Recipes\Domain).
 */
final class InvalidQuantity extends DomainException
{
    public static function notPositive(): self
    {
        return new self('La cantidad debe ser mayor que cero.');
    }

    public static function zeroDenominator(): self
    {
        return new self('Una fracción no puede tener cero como denominador.');
    }

    public static function unreadable(string $amount): self
    {
        return new self(sprintf('No se entiende la cantidad "%s". Usa, por ejemplo, 200, 1,5, 1/2, ½ o 1 ½.', $amount));
    }
}
