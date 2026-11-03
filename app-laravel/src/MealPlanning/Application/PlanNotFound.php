<?php

declare(strict_types=1);

namespace MealPlanning\Application;

use MealPlanning\Domain\NutritionPlanId;
use Shared\Application\NotFound;

/**
 * PlanNotFound: no existe ningún plan nutricional con ese identificador.
 *
 * La lanza GetNutritionPlan cuando el repositorio devuelve null. Como
 * RecipeNotFound y NoPlanInForce, hereda de NotFound (→ HTTP 404), anunciado
 * en el ADR 0008.
 *
 * CAPA: Aplicación (MealPlanning\Application).
 */
final class PlanNotFound extends NotFound
{
    public static function withId(NutritionPlanId $id): self
    {
        return new self(sprintf('No existe ningún plan nutricional con el identificador «%s».', $id->value()));
    }
}
