<?php

declare(strict_types=1);

namespace MealPlanning\Application;

use MealPlanning\Domain\PlanImportId;
use MealPlanning\Domain\PlanImportRepository;

/**
 * GetPlanImport: caso de uso «¿cómo va esta importación y qué le falta?».
 *
 * CONCEPTO: consulta (CQS), como GetNutritionPlan
 *   - recibe el id como texto (un primitivo);
 *   - devuelve un PlanImportView (DTO de lectura), nunca el agregado;
 *   - no cambia nada: se puede llamar tantas veces como se quiera;
 *   - si la importación no existe, PlanImportNotFound (→ 404);
 *   - si el id ni siquiera tiene forma de identificador, el dominio lanza
 *     InvalidPlanImportId (→ 422).
 *
 * Es la única pieza nueva del núcleo en HEXM-0033: los comandos de la
 * importación ya existían (HEXM-0032), y faltaba poder preguntar por ella
 * desde fuera (GET /api/plan-imports/{id}).
 *
 * CAPA: Aplicación (MealPlanning\Application).
 */
final readonly class GetPlanImport
{
    public function __construct(private PlanImportRepository $imports) {}

    public function __invoke(string $id): PlanImportView
    {
        $importId = PlanImportId::fromString($id);

        $import = $this->imports->find($importId) ?? throw PlanImportNotFound::withId($importId);

        return PlanImportView::fromImport($import);
    }
}
