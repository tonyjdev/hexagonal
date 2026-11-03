<?php

declare(strict_types=1);

namespace MealPlanning\Infrastructure\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use MealPlanning\Application\NutritionPlanSummaryView;

/**
 * NutritionPlanSummaryResource: el JSON de una línea del histórico.
 *
 * CONCEPTO: API Resource sobre un DTO de lectura (ADR 0008)
 * Con NutritionPlanSummaryResource::collection($views), Laravel aplica este
 * formato a cada elemento y envuelve la lista en "data", como el resto de
 * la API. Incluye la URL del plan (url), para que el cliente no la tenga que
 * construir a mano.
 *
 * CAPA: Infraestructura (MealPlanning\Infrastructure\Http).
 */
final class NutritionPlanSummaryResource extends JsonResource
{
    public function __construct(NutritionPlanSummaryView $plan)
    {
        parent::__construct($plan);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $plan = $this->resource;
        assert($plan instanceof NutritionPlanSummaryView);

        return [
            'id' => $plan->id,
            'number' => $plan->number,
            'starts_on' => $plan->startsOn,
            'ends_on' => $plan->endsOn,
            'status' => $plan->status,
            'status_label' => $plan->statusLabel,
            'url' => route('nutrition-plans.show', ['id' => $plan->id]),
        ];
    }
}
