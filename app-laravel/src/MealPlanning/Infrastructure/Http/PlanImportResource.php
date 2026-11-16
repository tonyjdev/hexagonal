<?php

declare(strict_types=1);

namespace MealPlanning\Infrastructure\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use MealPlanning\Application\PlanImportView;

/**
 * PlanImportResource: el JSON de una importación de plan (HEXM-0033).
 *
 *     {
 *       "data": {
 *         "id": "0196…", "number": 99,
 *         "status": "ready_to_confirm", "status_label": "lista para confirmar",
 *         "has_self_week": true,
 *         "plan_id": null, "plan_url": null,
 *         "url": "http://…/api/plan-imports/0196…"
 *       }
 *     }
 *
 * CONCEPTO: API Resource sobre un DTO de lectura (ADR 0008)
 * Recibe el PlanImportView, no el agregado, y solo decide los nombres de las
 * claves (en snake_case, como el resto de la API) y añade las URL, que son
 * cosa de HTTP. Con `url` y `plan_url` el cliente no tiene que componer
 * rutas: sigue los enlaces (una pizca de HATEOAS, sin más ambición).
 *
 * CAPA: Infraestructura (MealPlanning\Infrastructure\Http), adaptador de entrada.
 */
final class PlanImportResource extends JsonResource
{
    public function __construct(PlanImportView $import)
    {
        parent::__construct($import);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $import = $this->resource;
        assert($import instanceof PlanImportView);

        return [
            'id' => $import->id,
            'number' => $import->number,
            'status' => $import->status,
            'status_label' => $import->statusLabel,
            'has_self_week' => $import->hasSelfWeek,
            'plan_id' => $import->planId,
            'plan_url' => $import->planId === null ? null : route('nutrition-plans.show', ['id' => $import->planId]),
            'url' => route('plan-imports.show', ['id' => $import->id]),
        ];
    }
}
