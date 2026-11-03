<?php

declare(strict_types=1);

namespace MealPlanning\Application;

use DateTimeImmutable;
use MealPlanning\Domain\NutritionPlan;
use MealPlanning\Domain\ValidityPeriod;

/**
 * NutritionPlanSummaryView: una línea del histórico de planes.
 *
 *     plan 27   02/11/2026 → …            vigente
 *     plan 26   07/09/2026 → 01/11/2026   finalizado
 *
 * CONCEPTO: DTO de lectura (ADR 0008), resumen y no el plan entero
 * El listado no necesita las semanas ni las pautas: solo lo que identifica
 * a cada plan y su vigencia. Los datos completos están en NutritionPlanView
 * (GetNutritionPlan).
 *
 * CAPA: Aplicación (MealPlanning\Application).
 */
final readonly class NutritionPlanSummaryView
{
    public function __construct(
        public string $id,
        public int $number,
        /** AAAA-MM-DD */
        public string $startsOn,
        /** AAAA-MM-DD del último día en que rige, o null si no tiene fin. */
        public ?string $endsOn,
        /** 'in_force', 'finished' o 'upcoming' (PlanStatus). */
        public string $status,
        /** El estado en español: "vigente", "finalizado", "próximo". */
        public string $statusLabel,
    ) {}

    public static function fromPlan(NutritionPlan $plan, ValidityPeriod $period, DateTimeImmutable $today): self
    {
        $status = match (true) {
            $period->startsAfter($today) => PlanStatus::Upcoming,
            $period->hasEndedBefore($today) => PlanStatus::Finished,
            default => PlanStatus::InForce,
        };

        return new self(
            id: $plan->id()->value(),
            number: $plan->number()->value(),
            startsOn: $period->startsOn()->format('Y-m-d'),
            endsOn: $period->endsOn()?->format('Y-m-d'),
            status: $status->value,
            statusLabel: $status->label(),
        );
    }
}
