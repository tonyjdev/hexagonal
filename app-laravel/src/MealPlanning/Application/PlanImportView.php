<?php

declare(strict_types=1);

namespace MealPlanning\Application;

use MealPlanning\Domain\PlanImport;

/**
 * PlanImportView: cómo va una importación de plan, lista para mostrar.
 *
 *     importación del plan 99   pendiente de la semana 2
 *     importación del plan 99   lista para confirmar
 *     importación del plan 99   confirmada → plan 0196…
 *
 * CONCEPTO: DTO de lectura (ADR 0008)
 * Como NutritionPlanSummaryView: solo primitivos y nunca el agregado
 * PlanImport, que no sale de la aplicación. Responde a la pregunta del
 * usuario a mitad de una importación: «¿qué le falta?». Por eso no lleva el
 * contenido de los Excel (las pautas, los intercambios y los menús): se ven
 * en el plan, una vez confirmado (GetNutritionPlan).
 *
 * El estado no se guarda: lo deduce PlanImport::status() de lo que tiene la
 * importación (HEXM-0032). Aquí solo se traduce a texto.
 *
 * CAPA: Aplicación (MealPlanning\Application).
 */
final readonly class PlanImportView
{
    public function __construct(
        public string $id,
        /** El número de plan que trae el Excel de la nutricionista. */
        public int $number,
        /** 'awaiting_self_week', 'ready_to_confirm' o 'confirmed' (PlanImportStatus). */
        public string $status,
        /** El estado en español: "pendiente de la semana 2", "lista para confirmar", "confirmada". */
        public string $statusLabel,
        /** Si ya tiene el Excel de la semana 2. */
        public bool $hasSelfWeek,
        /** El id del plan creado al confirmarla, o null mientras está pendiente. */
        public ?string $planId,
    ) {}

    public static function fromImport(PlanImport $import): self
    {
        $status = $import->status();

        return new self(
            id: $import->id()->value(),
            number: $import->number()->value(),
            status: $status->value,
            statusLabel: $status->label(),
            hasSelfWeek: $import->selfWeek() !== null,
            planId: $import->plan()?->value(),
        );
    }
}
