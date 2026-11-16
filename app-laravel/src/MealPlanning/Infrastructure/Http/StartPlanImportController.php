<?php

declare(strict_types=1);

namespace MealPlanning\Infrastructure\Http;

use Illuminate\Http\JsonResponse;
use MealPlanning\Application\GetPlanImport;
use MealPlanning\Application\StartPlanImport;

/**
 * StartPlanImportController: POST /api/plan-imports (HEXM-0033).
 *
 * Empieza la importación de un plan con el Excel de la nutricionista
 * (multipart/form-data, campo `file`).
 *
 *   201 Created + Location   la importación, pendiente de la semana 2
 *   422                      no es un .xlsx (forma) o el Excel no tiene la
 *                            forma esperada (UnreadableDocument, del importador)
 *   503                      el servicio importer no responde (PlanReaderUnavailable)
 *
 * CONCEPTO: adaptador de entrada (driving) delgado
 * Traduce HTTP → caso de uso → HTTP y nada más: la Form Request convierte el
 * fichero en un PlanDocument, StartPlanImport lo lee con el puerto PlanReader
 * y guarda la importación, y los errores los traduce bootstrap/app.php (sin
 * try/catch aquí, ADR 0007).
 *
 * Por qué responde la importación entera y no solo su id (como el alta de un
 * plan): lo primero que quiere saber quien sube el Excel es si se ha leído
 * bien, qué número de plan trae y qué le falta. Se consulta con GetPlanImport
 * en lugar de que StartPlanImport devuelva el DTO, para no mezclar comando y
 * consulta (CQS): el comando solo devuelve el id que ha creado.
 *
 * CAPA: Infraestructura (MealPlanning\Infrastructure\Http).
 */
final readonly class StartPlanImportController
{
    public function __construct(
        private StartPlanImport $startPlanImport,
        private GetPlanImport $getPlanImport,
    ) {}

    public function __invoke(PlanDocumentRequest $request): JsonResponse
    {
        $id = ($this->startPlanImport)($request->toDocument());

        return (new PlanImportResource(($this->getPlanImport)($id->value())))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED)
            ->header('Location', route('plan-imports.show', ['id' => $id->value()]));
    }
}
