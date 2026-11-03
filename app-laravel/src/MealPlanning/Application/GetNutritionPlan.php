<?php

declare(strict_types=1);

namespace MealPlanning\Application;

use MealPlanning\Domain\NutritionPlanId;
use MealPlanning\Domain\NutritionPlanRepository;

/**
 * GetNutritionPlan: caso de uso "consultar un plan del histórico, entero".
 *
 * CONCEPTO: consulta (CQS), como Recipes\Application\GetRecipe
 *   - recibe el id como texto (un primitivo);
 *   - devuelve un NutritionPlanView (DTO de lectura), nunca el agregado;
 *   - si el plan no existe, PlanNotFound (→ 404);
 *   - si el id ni siquiera tiene forma de identificador, el dominio lanza
 *     InvalidNutritionPlanId (→ 422), como en las recetas (HEXM-0011).
 *
 * CAPA: Aplicación (MealPlanning\Application).
 */
final readonly class GetNutritionPlan
{
    public function __construct(private NutritionPlanRepository $plans) {}

    public function __invoke(string $id): NutritionPlanView
    {
        $planId = NutritionPlanId::fromString($id);

        $plan = $this->plans->find($planId) ?? throw PlanNotFound::withId($planId);

        return NutritionPlanView::fromPlan($plan);
    }
}
