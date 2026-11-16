<?php

declare(strict_types=1);

namespace MealPlanning\Infrastructure\Http;

use MealPlanning\Application\AttachSelfWeekToPlanImport;
use MealPlanning\Application\GetPlanImport;

/**
 * AttachSelfWeekController: PUT /api/plan-imports/{id}/self-week (HEXM-0033).
 *
 * Añade a una importación el Excel de la semana 2 (multipart/form-data,
 * campo `file`) o sustituye el que tenía.
 *
 *   200   la importación, lista para confirmar
 *   404   no existe la importación (PlanImportNotFound)
 *   422   no es un .xlsx, el Excel no tiene la forma esperada, el id está mal
 *         formado o la importación ya está confirmada (InvalidPlanImport)
 *   503   el servicio importer no responde
 *
 * CONCEPTO: PUT sobre un subrecurso
 * La semana 2 es un documento DE la importación, y como mucho hay uno: subir
 * otro lo sustituye. Eso es justo la semántica de PUT («deja este recurso
 * así»), que además es idempotente: repetir la misma subida deja la
 * importación igual. Un POST diría «añade otro», que no es lo que pasa.
 *
 * Nota técnica: PHP solo procesaba multipart/form-data en POST, y con PUT el
 * fichero no llegaba. Desde PHP 8.4, Symfony (y por tanto Laravel) lo lee
 * también en PUT con request_parse_body(), así que ya no hace falta el truco
 * de mandar un POST con `_method=PUT`.
 *
 * CAPA: Infraestructura (MealPlanning\Infrastructure\Http).
 */
final readonly class AttachSelfWeekController
{
    public function __construct(
        private AttachSelfWeekToPlanImport $attachSelfWeek,
        private GetPlanImport $getPlanImport,
    ) {}

    public function __invoke(PlanDocumentRequest $request, string $id): PlanImportResource
    {
        ($this->attachSelfWeek)($id, $request->toDocument());

        return new PlanImportResource(($this->getPlanImport)($id));
    }
}
