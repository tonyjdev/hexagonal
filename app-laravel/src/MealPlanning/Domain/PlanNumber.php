<?php

declare(strict_types=1);

namespace MealPlanning\Domain;

/**
 * PlanNumber: número con el que la nutricionista identifica un plan ("plan 27").
 *
 * CONCEPTO: Value Object frente a "primitive obsession"
 * Igual que Servings en el recetario: un int admite 0 o -5, y la regla "un
 * número de plan es positivo" tendría que repetirse allí donde llegue ese
 * int. Envuelto en un tipo propio, la regla vive en UN sitio: si tienes un
 * PlanNumber, es válido.
 *
 * DECISIÓN: el número NO es la identidad del plan
 * Sería tentador usar el 27 como identificador, pero lo asigna la
 * nutricionista, fuera de la aplicación: podría equivocarse, repetirlo o
 * cambiar de numeración. La identidad técnica es NutritionPlanId; el número
 * es un dato del negocio para mostrar y buscar ("¿qué cenaba en el plan
 * 25?", nivel 11).
 *
 * CAPA: Dominio (MealPlanning\Domain).
 */
final readonly class PlanNumber
{
    private function __construct(private int $value) {}

    public static function of(int $value): self
    {
        if ($value < 1) {
            throw InvalidPlanNumber::notPositive($value);
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
