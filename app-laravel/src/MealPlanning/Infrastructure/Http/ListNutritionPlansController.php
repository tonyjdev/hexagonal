<?php

declare(strict_types=1);

namespace MealPlanning\Infrastructure\Http;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use MealPlanning\Application\ListNutritionPlans;

/**
 * ListNutritionPlansController: GET /api/nutrition-plans, el histórico.
 *
 * CONCEPTO: adaptador driving de una consulta (como GetRecipeController)
 * Sin parámetros ni reglas: llama al caso de uso y da formato a la lista.
 * "Hoy" (para saber qué plan está vigente) lo pone el caso de uso con el
 * Clock; el controlador no sabe qué día es.
 *
 * CAPA: Infraestructura (MealPlanning\Infrastructure\Http).
 */
final readonly class ListNutritionPlansController
{
    public function __construct(private ListNutritionPlans $listNutritionPlans) {}

    public function __invoke(): AnonymousResourceCollection
    {
        return NutritionPlanSummaryResource::collection(($this->listNutritionPlans)());
    }
}
