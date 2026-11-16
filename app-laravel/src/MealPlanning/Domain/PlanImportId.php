<?php

declare(strict_types=1);

namespace MealPlanning\Domain;

use Shared\Domain\Uuid;

/**
 * PlanImportId: identificador de una importación de plan.
 *
 * El mismo patrón que NutritionPlanId y RecipeId: un value object que
 * envuelve un Uuid del shared kernel (UUID v7 generado en el dominio,
 * ADR 0005). Un tipo propio y no el NutritionPlanId: una importación NO es
 * un plan (todavía no lo es, y cuando se confirma, el plan tiene su propio
 * id), y PHPStan rechaza confundir un identificador con el otro.
 *
 * CAPA: Dominio (MealPlanning\Domain). Solo PHP nativo y el shared kernel.
 */
final readonly class PlanImportId
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
        return new self(Uuid::tryFromString($value) ?? throw InvalidPlanImportId::malformed($value));
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
