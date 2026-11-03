<?php

declare(strict_types=1);

namespace MealPlanning\Application;

use MealPlanning\Domain\NutritionPlanHistory;
use MealPlanning\Domain\NutritionPlanRepository;
use Shared\Application\Clock;

/**
 * ListNutritionPlans: caso de uso "el histórico de mis planes".
 *
 *     repositorio.history()  ──►  NutritionPlanHistory (dominio: periodos)
 *                                        │  + hoy (Clock)
 *                                        ▼
 *                            list<NutritionPlanSummaryView> (vigente, finalizado, próximo)
 *
 * CONCEPTO: caso de uso de consulta que orquesta (como GetMenuForDate)
 * No tiene reglas propias: los planes los da el repositorio, los periodos de
 * vigencia los deriva el dominio (NutritionPlanHistory, regla 19) y "hoy" lo
 * da el Clock. El caso de uso junta las tres cosas y las traduce a vistas.
 *
 * CAPA: Aplicación (MealPlanning\Application).
 */
final readonly class ListNutritionPlans
{
    public function __construct(
        private NutritionPlanRepository $plans,
        private Clock $clock,
    ) {}

    /**
     * @return list<NutritionPlanSummaryView> del más reciente al más antiguo
     */
    public function __invoke(): array
    {
        $history = NutritionPlanHistory::of($this->plans->history());
        $today = $this->clock->today();

        return array_map(
            fn ($plan): NutritionPlanSummaryView => NutritionPlanSummaryView::fromPlan($plan, $history->periodOf($plan), $today),
            $history->plans(),
        );
    }
}
