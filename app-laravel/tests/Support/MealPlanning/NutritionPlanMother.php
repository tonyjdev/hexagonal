<?php

declare(strict_types=1);

namespace Tests\Support\MealPlanning;

use DateTimeImmutable;
use MealPlanning\Application\RegisterNutritionPlanCommand;
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

    /**
     * El comando de alta del mismo plan 27 (RegisterNutritionPlan, HEXM-0021),
     * con los datos en el formato de los documentos y cambios opcionales en
     * sus argumentos. Como en los tests del alta de recetas, cada test estropea
     * UNA sola cosa.
     *
     * @param  array<string, mixed>  $changes  argumento del constructor → valor
     */
    public static function registerCommand(array $changes = []): RegisterNutritionPlanCommand
    {
        $menus = [];

        foreach (range(1, 6) as $menu) {
            $menus[] = ['menu' => $menu, 'lunch' => "Comida del menú {$menu}", 'dinner' => "Cena del menú {$menu}"];
        }

        $days = [];

        foreach (range(8, 14) as $day) {
            $days[] = $day === 13
                ? ['day' => 13, 'lunch' => null, 'dinner' => null]
                : [
                    'day' => $day,
                    'lunch' => ['name' => "Comida del día {$day}", 'ingredients' => [['name' => 'Pasta integral', 'quantity' => '80 g']]],
                    'dinner' => ['name' => "Cena del día {$day}", 'ingredients' => []],
                ];
        }

        $arguments = [
            'number' => 27,
            'startsOn' => new DateTimeImmutable('2026-11-02'),
            'fixedMeals' => [
                ['meal_type' => 'breakfast', 'description' => 'Café con leche desnatada', 'option_groups' => [
                    ['choose' => 1, 'options' => ['Tostada de pan integral con tomate', '30 g de copos de avena']],
                ]],
                ['meal_type' => 'afternoon_snack', 'description' => 'Infusión y 20 g de nueces', 'option_groups' => []],
            ],
            'exchanges' => [
                ['category' => 'carbohydrates', 'food' => 'Arroz', 'serving' => '60 g en crudo'],
                ['category' => 'protein', 'food' => 'Pechuga de pollo', 'serving' => '100 g'],
            ],
            'exchangeRules' => ['La grasa solo en la comida o en la cena, no en ambas.'],
            'nutritionistWeek' => $menus,
            'selfWeek' => $days,
            ...$changes,
        ];

        // PHPStan no puede seguir los tipos de un array armado así; en los tests
        // basta con que el constructor reciba los argumentos por nombre.
        /** @phpstan-ignore argument.type */
        return new RegisterNutritionPlanCommand(...$arguments);
    }
}
