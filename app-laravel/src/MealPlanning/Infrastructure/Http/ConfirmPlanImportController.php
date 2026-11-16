<?php

declare(strict_types=1);

namespace MealPlanning\Infrastructure\Http;

use Illuminate\Http\JsonResponse;
use MealPlanning\Application\ConfirmPlanImport;

/**
 * ConfirmPlanImportController: POST /api/plan-imports/{id}/confirm (HEXM-0033).
 *
 * Confirma una importación con la fecha de inicio (`starts_on`) y la
 * convierte en un plan nutricional.
 *
 *   201 Created + Location   {"id": "<id del plan>"}; Location apunta al plan
 *   404                      no existe la importación
 *   422                      falta la semana 2 (InvalidPlanImport), la fecha no
 *                            es un lunes, otro plan ya empieza ese día o tiene
 *                            ese número, o la importación ya está confirmada
 *
 * CONCEPTO: una acción como recurso (POST …/confirm)
 * Confirmar no es «cambiar un campo» de la importación (el estado ni
 * siquiera se guarda: se deduce, HEXM-0032), sino una operación de negocio
 * que crea OTRO recurso, el plan. Por eso es un POST a una acción con nombre
 * y no un PATCH {"status": "confirmed"}, que dejaría al cliente manejar el
 * estado y escondería la regla de negocio detrás de un dato.
 *
 * La respuesta es la misma que la del alta manual de un plan
 * (RegisterNutritionPlanController): 201, el id del plan y su Location.
 * No es casualidad: por debajo, ConfirmPlanImport llama a
 * RegisterNutritionPlan.
 *
 * CAPA: Infraestructura (MealPlanning\Infrastructure\Http).
 */
final readonly class ConfirmPlanImportController
{
    public function __construct(private ConfirmPlanImport $confirmPlanImport) {}

    public function __invoke(ConfirmPlanImportRequest $request, string $id): JsonResponse
    {
        $planId = ($this->confirmPlanImport)($id, $request->startsOn());

        return new JsonResponse(
            ['id' => $planId->value()],
            JsonResponse::HTTP_CREATED,
            ['Location' => route('nutrition-plans.show', ['id' => $planId->value()])],
        );
    }
}
