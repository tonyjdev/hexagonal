<?php

declare(strict_types=1);

namespace Tests\Support\MealPlanning;

use DateTimeImmutable;
use MealPlanning\Domain\Dish;
use MealPlanning\Domain\DishIngredient;
use MealPlanning\Domain\Exchange;
use MealPlanning\Domain\ExchangeCategory;
use MealPlanning\Domain\ExchangeTable;
use MealPlanning\Domain\FixedMeal;
use MealPlanning\Domain\MealOption;
use MealPlanning\Domain\MealType;
use MealPlanning\Domain\MenuWeek;
use MealPlanning\Domain\NutritionPlan;
use MealPlanning\Domain\NutritionPlanId;
use MealPlanning\Domain\OptionGroup;
use MealPlanning\Domain\PlanNumber;

/**
 * NutritionPlanMother: planes nutricionales de ejemplo para los tests.
 *
 * CONCEPTO: Object Mother (como Tests\Support\Recipes\RecipeMother)
 * Una clase que crea objetos de ejemplo ya válidos. Hasta HEXM-0017, cada
 * test de MealPlanning montaba su plan con funciones auxiliares propias
 * (plan27(), calendarPlan()...), que en Pest son GLOBALES y obligan a
 * inventar prefijos para que no choquen. Una clase con métodos estáticos
 * vive en su namespace, se autocarga (Tests\ → tests/) y la pueden usar los
 * tests de contrato de hoy, el caso de uso de HEXM-0019 y el adaptador de
 * Eloquent del nivel 5.
 *
 * Los platos dicen de dónde vienen ("Comida del menú 3", "Comida del día
 * 10") para poder comprobar qué semana y qué día ha tocado.
 *
 * Datos ficticios inspirados en el plan 27 (CONVENCIONES.md, §6).
 */
final class NutritionPlanMother
{
    /**
     * Un plan completo: pautas fijas, tabla de intercambios con reglas y las
     * dos semanas. Cubre todo lo que un adaptador tiene que saber guardar.
     */
    public static function plan(int $number = 27, string $startsOn = '2026-11-02', ?NutritionPlanId $id = null): NutritionPlan
    {
        return NutritionPlan::create(
            $id ?? NutritionPlanId::generate(),
            PlanNumber::of($number),
            new DateTimeImmutable($startsOn),
            self::fixedMeals(),
            self::exchangeTable(),
            [self::nutritionistWeek(), self::selfWeek()],
        );
    }

    /**
     * Semana 1: menús 1 a 6 de la nutricionista, en texto libre.
     */
    public static function nutritionistWeek(): MenuWeek
    {
        $menus = [];

        foreach (range(1, 6) as $menu) {
            $menus[$menu] = [
                'lunch' => Dish::described("Comida del menú {$menu}"),
                'dinner' => Dish::described("Cena del menú {$menu}"),
            ];
        }

        return MenuWeek::fromNutritionistMenus($menus);
    }

    /**
     * Semana 2: días 8 a 14 del usuario, con el 13 (sábado) vacío.
     */
    public static function selfWeek(): MenuWeek
    {
        $days = [];

        foreach (range(8, 14) as $day) {
            $days[$day] = $day === 13 ? null : [
                'lunch' => Dish::named("Comida del día {$day}", [DishIngredient::of('Pasta integral', '80 g')]),
                'dinner' => Dish::named("Cena del día {$day}"),
            ];
        }

        return MenuWeek::fromSelfDays($days);
    }

    /**
     * @return list<FixedMeal>
     */
    public static function fixedMeals(): array
    {
        return [
            FixedMeal::of(MealType::Breakfast, 'Café con leche desnatada', [
                OptionGroup::choose(1, [
                    MealOption::of('Tostada de pan integral (40 g) con tomate'),
                    MealOption::of('30 g de copos de avena'),
                ]),
            ]),
            FixedMeal::of(MealType::AfternoonSnack, 'Infusión y 20 g de nueces'),
        ];
    }

    public static function exchangeTable(): ExchangeTable
    {
        return ExchangeTable::of([
            Exchange::of(ExchangeCategory::Carbohydrates, 'Arroz', '60 g en crudo'),
            Exchange::of(ExchangeCategory::Protein, 'Pechuga de pollo', '100 g'),
        ], [
            'La grasa solo en la comida o en la cena, no en ambas.',
        ]);
    }
}
