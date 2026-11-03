<?php

declare(strict_types=1);

namespace MealPlanning\Application;

use MealPlanning\Domain\Exchange;
use MealPlanning\Domain\ExchangeCategory;
use MealPlanning\Domain\FixedMeal;
use MealPlanning\Domain\MealOption;
use MealPlanning\Domain\MenuDay;
use MealPlanning\Domain\MenuWeek;
use MealPlanning\Domain\NutritionPlan;
use MealPlanning\Domain\OptionGroup;

/**
 * NutritionPlanView: un plan nutricional completo, listo para mostrar. Es lo
 * que devuelve GetNutritionPlan.
 *
 * CONCEPTO: DTO de lectura de un agregado grande (ADR 0008)
 * Como RecipeView, solo primitivos y otras vistas: el agregado
 * NutritionPlan, con sus value objects y enums, no sale de la aplicación.
 * Para no multiplicar clases, las partes pequeñas (pautas fijas, intercambios,
 * días) van como arrays con forma fija, documentada con PHPDoc para PHPStan;
 * los platos reutilizan DishView (HEXM-0019).
 *
 * Los enums se exponen con su código y su etiqueta en español
 * ("breakfast" / "desayuno"): el código para los programas, la etiqueta para
 * las personas.
 *
 * CAPA: Aplicación (MealPlanning\Application).
 */
final readonly class NutritionPlanView
{
    /**
     * @param  list<array{meal_type: string, meal_type_label: string, description: string|null, option_groups: list<array{choose: int, options: list<string>}>}>  $fixedMeals  en el orden del día
     * @param  list<array{category: string, category_label: string, food: string, serving: string}>  $exchanges  por categoría y en el orden del documento
     * @param  list<string>  $exchangeRules
     * @param  list<array{number: int, source: string, source_label: string, days: list<array{weekday_number: int, weekday: string, lunch: DishView, dinner: DishView}>}>  $weeks  la 1 y la 2
     */
    public function __construct(
        public string $id,
        public int $number,
        public string $startsOn,
        public array $fixedMeals,
        public array $exchanges,
        public array $exchangeRules,
        public array $weeks,
    ) {}

    public static function fromPlan(NutritionPlan $plan): self
    {
        $exchanges = [];

        foreach (ExchangeCategory::cases() as $category) {
            foreach ($plan->exchangeTable()->exchangesIn($category) as $exchange) {
                $exchanges[] = self::exchange($exchange);
            }
        }

        return new self(
            id: $plan->id()->value(),
            number: $plan->number()->value(),
            startsOn: $plan->startsOn()->format('Y-m-d'),
            fixedMeals: array_map(self::fixedMeal(...), $plan->fixedMeals()),
            exchanges: $exchanges,
            exchangeRules: $plan->exchangeTable()->rules(),
            weeks: array_map(self::week(...), $plan->weeks()),
        );
    }

    /**
     * @return array{meal_type: string, meal_type_label: string, description: string|null, option_groups: list<array{choose: int, options: list<string>}>}
     */
    private static function fixedMeal(FixedMeal $meal): array
    {
        return [
            'meal_type' => $meal->mealType()->value,
            'meal_type_label' => $meal->mealType()->label(),
            'description' => $meal->description(),
            'option_groups' => array_map(fn (OptionGroup $group): array => [
                'choose' => $group->count(),
                'options' => array_map(fn (MealOption $option): string => $option->text(), $group->options()),
            ], $meal->optionGroups()),
        ];
    }

    /**
     * @return array{category: string, category_label: string, food: string, serving: string}
     */
    private static function exchange(Exchange $exchange): array
    {
        return [
            'category' => $exchange->category()->value,
            'category_label' => $exchange->category()->label(),
            'food' => $exchange->food(),
            'serving' => $exchange->serving(),
        ];
    }

    /**
     * @return array{number: int, source: string, source_label: string, days: list<array{weekday_number: int, weekday: string, lunch: DishView, dinner: DishView}>}
     */
    private static function week(MenuWeek $week): array
    {
        return [
            'number' => $week->number(),
            'source' => $week->source()->value,
            'source_label' => $week->source()->label(),
            'days' => array_map(fn (MenuDay $day): array => [
                'weekday_number' => $day->weekday()->value,
                'weekday' => $day->weekday()->label(),
                'lunch' => DishView::fromDish($day->lunch()),
                'dinner' => DishView::fromDish($day->dinner()),
            ], $week->days()),
        ];
    }
}
