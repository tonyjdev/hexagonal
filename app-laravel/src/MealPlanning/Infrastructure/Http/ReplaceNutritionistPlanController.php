<?php

declare(strict_types=1);

namespace MealPlanning\Infrastructure\Http;

use MealPlanning\Application\GetPlanImport;
use MealPlanning\Application\ReplaceImportedNutritionistPlan;

/**
 * ReplaceNutritionistPlanController: PUT /api/plan-imports/{id}/nutritionist-plan
 * (HEXM-0033).
 *
 * Sustituye el Excel de la nutricionista de una importación pendiente
 * (multipart/form-data, campo `file`), por ejemplo porque se corrigió una
 * celda. Conserva la semana 2, si ya la tenía (regla 27).
 *
 *   200   la importación con el Excel nuevo
 *   404   no existe la importación
 *   422   no es un .xlsx, el Excel no tiene la forma esperada, el id está mal
 *         formado o la importación ya está confirmada
 *   503   el servicio importer no responde
 *
 * Mismo razonamiento que la semana 2 (AttachSelfWeekController): un único
 * documento por importación que se sustituye, por eso PUT.
 *
 * CAPA: Infraestructura (MealPlanning\Infrastructure\Http).
 */
final readonly class ReplaceNutritionistPlanController
{
    public function __construct(
        private ReplaceImportedNutritionistPlan $replaceNutritionistPlan,
        private GetPlanImport $getPlanImport,
    ) {}

    public function __invoke(PlanDocumentRequest $request, string $id): PlanImportResource
    {
        ($this->replaceNutritionistPlan)($id, $request->toDocument());

        return new PlanImportResource(($this->getPlanImport)($id));
    }
}
