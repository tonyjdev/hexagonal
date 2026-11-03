<?php

declare(strict_types=1);

namespace MealPlanning\Infrastructure\Http;

use MealPlanning\Application\GetNutritionPlan;

/**
 * GetNutritionPlanController: GET /api/nutrition-plans/{id}, un plan entero.
 *
 * CONCEPTO: adaptador driving de una consulta (como GetRecipeController)
 * El id llega tal cual: si no tiene forma de identificador, el dominio lanza
 * InvalidNutritionPlanId (→ 422); si no existe, el caso de uso lanza
 * PlanNotFound (→ 404). Los dos los traduce el manejador global.
 *
 * CAPA: Infraestructura (MealPlanning\Infrastructure\Http).
 */
final readonly class GetNutritionPlanController
{
    public function __construct(private GetNutritionPlan $getNutritionPlan) {}

    public function __invoke(string $id): NutritionPlanResource
    {
        return new NutritionPlanResource(($this->getNutritionPlan)($id));
    }
}
