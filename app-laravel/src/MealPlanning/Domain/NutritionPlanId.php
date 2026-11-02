<?php

declare(strict_types=1);

namespace MealPlanning\Domain;

use Shared\Domain\Uuid;

/**
 * NutritionPlanId: identificador de un plan nutricional.
 *
 * CONCEPTO: segundo bounded context, mismo patrón
 * Es el gemelo de Recipes\Domain\RecipeId: un value object que envuelve un
 * Uuid del shared kernel (composición, no herencia). MealPlanning no importa
 * nada de Recipes; lo que comparten los dos identificadores es solo la
 * técnica (Shared\Domain\Uuid), no el concepto.
 *
 * Por qué no reutilizar RecipeId ni un "Id" genérico: un tipo por concepto
 * hace que PHPStan rechace pasar el identificador de un plan donde se espera
 * el de una receta. Con strings, o con un único tipo Id, ese error pasaría
 * desapercibido hasta producción.
 *
 * El plan (NutritionPlan, HEXM-0015) nace con su identidad generada aquí, en
 * el dominio, igual que las recetas (ADR 0005).
 *
 * CAPA: Dominio (MealPlanning\Domain). Solo PHP nativo y el shared kernel.
 */
final readonly class NutritionPlanId
{
    private function __construct(private Uuid $uuid) {}

    public static function generate(): self
    {
        return new self(Uuid::v7());
    }

    /**
     * Reconstruye un identificador existente; acepta cualquier versión de
     * UUID bien formada y la normaliza a minúsculas.
     */
    public static function fromString(string $value): self
    {
        return new self(Uuid::tryFromString($value) ?? throw InvalidNutritionPlanId::malformed($value));
    }

    public function value(): string
    {
        return $this->uuid->value();
    }

    public function equals(self $other): bool
    {
        return $this->uuid->equals($other->uuid);
    }
}
