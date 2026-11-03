<?php

declare(strict_types=1);

namespace MealPlanning\Application;

use MealPlanning\Domain\Dish;
use MealPlanning\Domain\DishIngredient;
use MealPlanning\Domain\DuplicatedPlanStart;
use MealPlanning\Domain\Exchange;
use MealPlanning\Domain\ExchangeCategory;
use MealPlanning\Domain\ExchangeTable;
use MealPlanning\Domain\FixedMeal;
use MealPlanning\Domain\InvalidExchangeTable;
use MealPlanning\Domain\InvalidFixedMeal;
use MealPlanning\Domain\InvalidMenuWeek;
use MealPlanning\Domain\MealOption;
use MealPlanning\Domain\MealType;
use MealPlanning\Domain\MenuWeek;
use MealPlanning\Domain\NutritionPlan;
use MealPlanning\Domain\NutritionPlanId;
use MealPlanning\Domain\NutritionPlanRepository;
use MealPlanning\Domain\OptionGroup;
use MealPlanning\Domain\PlanNumber;
use Shared\Domain\DomainException;

/**
 * RegisterNutritionPlan: caso de uso "dar de alta un plan nutricional".
 *
 * CONCEPTO: comando de un agregado complejo (CQS: cambia el estado y solo
 * devuelve el id de lo creado, como CreateRecipe)
 *
 *     RegisterNutritionPlanCommand (primitivos, formato de los documentos)
 *        │  traducción: primitivos → value objects y entidades
 *        ▼
 *     NutritionPlan::create(...)   ← el dominio comprueba TODAS las reglas
 *        │
 *        ▼
 *     NutritionPlanRepository::save()   ← y las reglas entre planes (ADR 0011)
 *
 * El caso de uso TRADUCE, no decide. Qué es un plato válido, un "elige N"
 * correcto o una semana completa lo siguen diciendo Dish, OptionGroup y
 * MenuWeek. Aquí solo se convierte cada primitivo en su objeto del dominio,
 * con los constructores de formato para las semanas (la regla "el menú 6 es
 * el domingo" vive en MenuWeek, no aquí).
 *
 * Lo único que añade es lo que el dominio no puede ver: un texto que no es
 * un MealType o un ExchangeCategory (dentro del dominio son enums cerrados)
 * y un menú o día repetido (al indexar por número, el segundo pisaría al
 * primero sin que nadie lo notara). Son errores de TRADUCCIÓN y se lanzan con
 * excepciones del dominio, para que lleguen al usuario en español (como
 * InvalidIngredient::unknownUnit() en CreateRecipe).
 *
 * CONCEPTO: todo o nada
 * Si algo no es válido, el dominio lanza la excepción ANTES de guardar: no
 * se guarda medio plan. Y guardar es una única fila (ADR 0013), así que el
 * alta es atómica sin abrir aquí una transacción.
 *
 * CAPA: Aplicación (MealPlanning\Application).
 */
final readonly class RegisterNutritionPlan
{
    public function __construct(private NutritionPlanRepository $plans) {}

    /**
     * @throws DomainException si el plan incumple una regla del negocio
     * @throws DuplicatedPlanStart si otro plan ya empieza ese día
     */
    public function __invoke(RegisterNutritionPlanCommand $command): NutritionPlanId
    {
        $plan = NutritionPlan::create(
            NutritionPlanId::generate(),
            PlanNumber::of($command->number),
            $command->startsOn,
            array_map(self::toFixedMeal(...), $command->fixedMeals),
            self::toExchangeTable($command->exchanges, $command->exchangeRules),
            [self::toNutritionistWeek($command->nutritionistWeek), self::toSelfWeek($command->selfWeek)],
        );

        $this->plans->save($plan);

        return $plan->id();
    }

    /**
     * @param  array{meal_type: string, description: string|null, option_groups: list<array{choose: int, options: list<string>}>}  $data
     */
    private static function toFixedMeal(array $data): FixedMeal
    {
        return FixedMeal::of(
            MealType::tryFrom($data['meal_type']) ?? throw InvalidFixedMeal::unknownMealType($data['meal_type']),
            $data['description'],
            array_map(
                fn (array $group): OptionGroup => OptionGroup::choose(
                    $group['choose'],
                    array_map(MealOption::of(...), $group['options']),
                ),
                $data['option_groups'],
            ),
        );
    }

    /**
     * @param  list<array{category: string, food: string, serving: string}>  $exchanges
     * @param  list<string>  $rules
     */
    private static function toExchangeTable(array $exchanges, array $rules): ExchangeTable
    {
        return ExchangeTable::of(
            array_map(
                fn (array $exchange): Exchange => Exchange::of(
                    ExchangeCategory::tryFrom($exchange['category']) ?? throw InvalidExchangeTable::unknownCategory($exchange['category']),
                    $exchange['food'],
                    $exchange['serving'],
                ),
                $exchanges,
            ),
            $rules,
        );
    }

    /**
     * Semana 1: [{menu: 1, lunch: "…", dinner: "…"}, …] → menús indexados por
     * número, como los espera MenuWeek::fromNutritionistMenus().
     *
     * @param  list<array{menu: int, lunch: string|null, dinner: string|null}>  $menus
     */
    private static function toNutritionistWeek(array $menus): MenuWeek
    {
        $byNumber = [];

        foreach ($menus as $menu) {
            if (array_key_exists($menu['menu'], $byNumber)) {
                throw InvalidMenuWeek::duplicatedNutritionistMenu($menu['menu']);
            }

            // Un plato que falta se pasa como null: MenuDay dice cuál falta
            // ("al lunes le falta la comida"), con mejor mensaje que un
            // plato vacío.
            $byNumber[$menu['menu']] = [
                'lunch' => $menu['lunch'] === null ? null : Dish::described($menu['lunch']),
                'dinner' => $menu['dinner'] === null ? null : Dish::described($menu['dinner']),
            ];
        }

        return MenuWeek::fromNutritionistMenus($byNumber);
    }

    /**
     * Semana 2: [{day: 8, lunch: {name, ingredients}, dinner: …}, …] → días
     * indexados por número para MenuWeek::fromSelfDays(). Un día sin comida
     * ni cena es un día vacío (el 13, el sábado); si es otro día, la semana
     * se queda corta y lo dice MenuWeek.
     *
     * @param  list<array{day: int, lunch: array{name: string, ingredients: list<array{name: string, quantity: string|null}>}|null, dinner: array{name: string, ingredients: list<array{name: string, quantity: string|null}>}|null}>  $days
     */
    private static function toSelfWeek(array $days): MenuWeek
    {
        $byNumber = [];

        foreach ($days as $day) {
            if (array_key_exists($day['day'], $byNumber)) {
                throw InvalidMenuWeek::duplicatedSelfDay($day['day']);
            }

            $byNumber[$day['day']] = $day['lunch'] === null && $day['dinner'] === null ? null : [
                'lunch' => self::toNamedDish($day['lunch']),
                'dinner' => self::toNamedDish($day['dinner']),
            ];
        }

        return MenuWeek::fromSelfDays($byNumber);
    }

    /**
     * @param  array{name: string, ingredients: list<array{name: string, quantity: string|null}>}|null  $dish
     */
    private static function toNamedDish(?array $dish): ?Dish
    {
        if ($dish === null) {
            return null;
        }

        return Dish::named(
            $dish['name'],
            array_map(
                fn (array $ingredient): DishIngredient => DishIngredient::of($ingredient['name'], $ingredient['quantity']),
                $dish['ingredients'],
            ),
        );
    }
}
