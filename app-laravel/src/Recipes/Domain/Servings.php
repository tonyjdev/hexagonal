<?php

declare(strict_types=1);

namespace Recipes\Domain;

/**
 * Servings: número de raciones que salen de una receta.
 *
 * CONCEPTO: Value Object frente a "primitive obsession"
 * Podríamos usar un simple `int $servings` en la receta. El problema es que
 * un int admite 0 o -3, y entonces la regla "una receta da al menos una
 * ración" tendría que comprobarse en cada sitio que reciba ese int. Envolverlo
 * en un tipo propio pone la regla en UN SOLO SITIO: si tienes un Servings,
 * es válido. A abusar de tipos primitivos para conceptos del negocio se le
 * llama "primitive obsession".
 *
 * Más adelante (ideas del roadmap: escalar raciones) aquí podría vivir el
 * cálculo del factor de escala entre dos Servings.
 *
 * CAPA: Dominio (Recipes\Domain).
 */
final readonly class Servings
{
    private function __construct(private int $value) {}

    public static function of(int $value): self
    {
        if ($value < 1) {
            throw InvalidServings::notPositive();
        }

        return new self($value);
    }

    public function value(): int
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
