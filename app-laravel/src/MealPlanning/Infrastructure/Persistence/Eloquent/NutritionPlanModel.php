<?php

declare(strict_types=1);

namespace MealPlanning\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * NutritionPlanModel: una fila de la tabla nutrition_plans.
 *
 * CONCEPTO: modelo de persistencia frente a modelo de dominio (ADR 0006)
 * Como RecipeModel: no es el agregado NutritionPlan, sino la forma en que se
 * guarda. Es un detalle del adaptador, vive en su Infrastructure y nunca
 * sale de aquí; NutritionPlanMapper traduce entre los dos.
 *
 * Aquí la distancia entre los dos modelos es aún mayor que en el recetario:
 * el agregado es un árbol de objetos con invariantes, y la fila son tres
 * columnas normales y tres documentos JSON (ADR 0013).
 *
 * CONCEPTO: cast 'array' de Eloquent
 * Convierte la columna jsonb en un array de PHP al leer (json_decode) y al
 * revés al escribir (json_encode). Para Eloquent el contenido es un array
 * cualquiera (mixed): darle forma y validarlo es trabajo del mapper.
 *
 * starts_on no tiene cast: una columna date de PostgreSQL llega como texto
 * 'AAAA-MM-DD', que es justo lo que se quiere (una fecha del calendario sin
 * hora). Un cast 'date' la convertiría en Carbon, que el dominio no usa.
 *
 * @property string $id
 * @property int $number
 * @property string $starts_on
 * @property array<mixed> $fixed_meals
 * @property array<mixed> $exchange_table
 * @property array<mixed> $weeks
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table('nutrition_plans', keyType: 'string', incrementing: false)]
#[Unguarded]
final class NutritionPlanModel extends Model
{
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'fixed_meals' => 'array',
            'exchange_table' => 'array',
            'weeks' => 'array',
        ];
    }
}
