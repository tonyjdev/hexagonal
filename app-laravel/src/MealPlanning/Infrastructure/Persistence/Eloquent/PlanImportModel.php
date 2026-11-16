<?php

declare(strict_types=1);

namespace MealPlanning\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * PlanImportModel: una fila de la tabla plan_imports.
 *
 * Modelo de persistencia, no el agregado (como NutritionPlanModel, ADR 0006):
 * vive aquí y nunca sale del adaptador; PlanImportMapper traduce entre los
 * dos. Los documentos jsonb llegan como arrays de PHP con el cast 'array'
 * (self_week, a null cuando la columna es NULL).
 *
 * @property string $id
 * @property string $status
 * @property array<mixed> $nutritionist_plan
 * @property array<mixed>|null $self_week
 * @property string|null $nutrition_plan_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table('plan_imports', keyType: 'string', incrementing: false)]
#[Unguarded]
final class PlanImportModel extends Model
{
    protected function casts(): array
    {
        return [
            'nutritionist_plan' => 'array',
            'self_week' => 'array',
        ];
    }
}
