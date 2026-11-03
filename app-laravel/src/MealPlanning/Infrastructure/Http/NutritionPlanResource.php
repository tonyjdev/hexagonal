<?php

declare(strict_types=1);

namespace MealPlanning\Infrastructure\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use MealPlanning\Application\DishIngredientView;
use MealPlanning\Application\DishView;
use MealPlanning\Application\NutritionPlanView;

/**
 * NutritionPlanResource: el JSON de un plan nutricional completo.
 *
 * CONCEPTO: API Resource sobre un DTO de lectura (ADR 0008)
 * NutritionPlanView ya trae las pautas, los intercambios y las semanas con
 * claves en snake_case; aquí solo se convierten los platos (DishView) en
 * arrays, con la misma forma que en GET /api/menu (ScheduledDayResource),
 * para que un plato se lea igual en toda la API.
 *
 * CAPA: Infraestructura (MealPlanning\Infrastructure\Http).
 */
final class NutritionPlanResource extends JsonResource
{
    public function __construct(NutritionPlanView $plan)
    {
        parent::__construct($plan);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $plan = $this->resource;
        assert($plan instanceof NutritionPlanView);

        return [
            'id' => $plan->id,
            'number' => $plan->number,
            'starts_on' => $plan->startsOn,
            'fixed_meals' => $plan->fixedMeals,
            'exchanges' => $plan->exchanges,
            'exchange_rules' => $plan->exchangeRules,
            'weeks' => array_map(fn (array $week): array => [
                'number' => $week['number'],
                'source' => $week['source'],
                'source_label' => $week['source_label'],
                'days' => array_map(fn (array $day): array => [
                    'weekday_number' => $day['weekday_number'],
                    'weekday' => $day['weekday'],
                    'lunch' => self::dish($day['lunch']),
                    'dinner' => self::dish($day['dinner']),
                ], $week['days']),
            ], $plan->weeks),
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
