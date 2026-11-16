<?php

declare(strict_types=1);

namespace MealPlanning\Infrastructure\Http;

use MealPlanning\Application\GetPlanImport;

/**
 * GetPlanImportController: GET /api/plan-imports/{id} (HEXM-0033).
 *
 * «¿Cómo va esta importación y qué le falta?»
 *
 *   200   la importación (PlanImportResource)
 *   404   no existe (PlanImportNotFound)
 *   422   el id no tiene forma de identificador (InvalidPlanImportId)
 *
 * Como GetNutritionPlanController: un adaptador de entrada sobre una
 * consulta, sin lógica.
 *
 * CAPA: Infraestructura (MealPlanning\Infrastructure\Http).
 */
final readonly class GetPlanImportController
{
    public function __construct(private GetPlanImport $getPlanImport) {}

    public function __invoke(string $id): PlanImportResource
    {
        return new PlanImportResource(($this->getPlanImport)($id));
    }
}
