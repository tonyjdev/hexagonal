<?php

declare(strict_types=1);

namespace MealPlanning\Infrastructure\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use MealPlanning\Application\DishIngredientView;
use MealPlanning\Application\DishView;
use MealPlanning\Application\ScheduledDayView;

/**
 * ScheduledDayResource: el JSON de "lo que me toca comer un día".
 *
 * CONCEPTO: API Resource sobre un DTO de lectura (ADR 0008, como RecipeResource)
 * El caso de uso devuelve un ScheduledDayView con primitivos; esta clase solo
 * decide cómo se escribe en JSON: claves en snake_case, el envoltorio "data"
 * de Laravel y la forma de los platos. Si mañana la API cambia de formato,
 * se toca aquí, no en el núcleo.
 *
 * Dos formas, con las mismas claves:
 *   - un día con menú: is_free_day false, lunch y dinner con su plato;
 *   - el día libre (sábado): is_free_day true, lunch y dinner a null.
 * Mantener las claves también en el día libre (en vez de quitarlas) permite
 * al cliente leer siempre la misma estructura.
 *
 * CAPA: Infraestructura (MealPlanning\Infrastructure\Http).
 */
final class ScheduledDayResource extends JsonResource
{
    public function __construct(ScheduledDayView $day)
    {
        parent::__construct($day);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $day = $this->resource;
        assert($day instanceof ScheduledDayView);

        return [
            'date' => $day->date,
            'plan_number' => $day->planNumber,
            'week_number' => $day->weekNumber,
            'weekday_number' => $day->weekdayNumber,
            'weekday' => $day->weekday,
            'is_free_day' => $day->isFreeDay,
            'lunch' => $day->lunch === null ? null : self::dish($day->lunch),
            'dinner' => $day->dinner === null ? null : self::dish($day->dinner),
        ];
    }

    /**
     * @return array{name: string|null, description: string|null, ingredients: list<array{name: string, quantity: string|null}>, recipe_id: string|null}
     */
    private static function dish(DishView $dish): array
    {
        return [
            'name' => $dish->name,
            'description' => $dish->description,
            'ingredients' => array_map(
                fn (DishIngredientView $ingredient): array => ['name' => $ingredient->name, 'quantity' => $ingredient->quantity],
                $dish->ingredients,
            ),
            'recipe_id' => $dish->recipeId,
        ];
    }
}
