<?php

declare(strict_types=1);

namespace MealPlanning\Infrastructure\Http;

use Illuminate\Http\JsonResponse;
use MealPlanning\Application\RegisterNutritionPlan;

/**
 * RegisterNutritionPlanController: POST /api/nutrition-plans, el alta manual
 * de un plan nutricional.
 *
 * CONCEPTO: adaptador driving (de entrada), como CreateRecipeController
 * Traduce HTTP al lenguaje del núcleo y de vuelta, y nada más:
 *   petición → (RegisterNutritionPlanRequest valida la forma) → comando →
 *   caso de uso → id → 201 {"id": "…"}.
 * No hay ni una regla del negocio aquí. Los errores del dominio (un martes de
 * inicio, una semana con cinco días, otro plan el mismo día) los convierte en
 * 422 el manejador global de bootstrap/app.php, sin que este controlador los
 * conozca (ADR 0007).
 *
 * Sin cabecera Location por ahora: todavía no existe GET /api/nutrition-plans/{id}
 * (llegará en HEXM-0023, con el histórico de planes).
 *
 * CAPA: Infraestructura (MealPlanning\Infrastructure\Http).
 */
final readonly class RegisterNutritionPlanController
{
    public function __construct(private RegisterNutritionPlan $registerNutritionPlan) {}

    public function __invoke(RegisterNutritionPlanRequest $request): JsonResponse
    {
        $id = ($this->registerNutritionPlan)($request->toCommand());

        return new JsonResponse(['id' => $id->value()], JsonResponse::HTTP_CREATED);
    }
}
