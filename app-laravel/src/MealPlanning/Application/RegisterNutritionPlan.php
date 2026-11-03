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
use MealPlanning\Domain\RecipeReference;
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
 *     RecipeCatalog::exists()   ← ¿existen las recetas de los platos? (HEXM-0025)
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
 * CONCEPTO: validar contra otro bounded context a través de un puerto propio
 * Regla 6: si un plato apunta a una receta, la receta tiene que existir. El
 * dominio de MealPlanning no lo puede saber (no conoce el recetario), así que
 * lo pregunta el caso de uso al puerto RecipeCatalog, que es de MealPlanning
 * y habla con RecipeReference. Quién responde (hoy, la capa de aplicación de
 * Recipes; mañana, quizá un servicio externo) es cosa del adaptador.
 *
 * DECISIÓN: preguntar DESPUÉS de construir el plan y ANTES de guardarlo
 * Primero las reglas que no cuestan nada (el dominio, en memoria); solo si el
 * plan es válido se consulta al otro contexto, una vez por receta distinta.
 * Así un plan que empieza en martes no gasta consultas, y el error que ve el
 * usuario es el más básico.
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
    public function __construct(
        private NutritionPlanRepository $plans,
        private RecipeCatalog $recipes,
    ) {}

    /**
     * @throws DomainException si el plan incumple una regla del negocio
     * @throws UnknownRecipe si un plato apunta a una receta que no existe
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

        $this->ensureRecipesExist($plan);

        $this->plans->save($plan);

        return $plan->id();
    }

    /**
     * Recorre los platos de las dos semanas y pregunta al recetario por cada
     * receta distinta. Se recorre el agregado ya construido (y no los datos
     * del comando) porque es la fuente de verdad: lo que se va a guardar.
     *
     * @throws UnknownRecipe con el primer plato cuya receta no existe
     */
    private function ensureRecipesExist(NutritionPlan $plan): void
    {
        // Una receta puede repetirse en varios platos (el mismo guiso dos
        // días): se pregunta una sola vez por cada una.
        $checked = [];

        foreach ($plan->weeks() as $week) {
            foreach ($week->days() as $day) {
                foreach ([$day->lunch(), $day->dinner()] as $dish) {
                    $recipe = $dish->recipe();

                    if ($recipe === null || array_key_exists($recipe->value(), $checked)) {
                        continue;
                    }

                    if (! $this->recipes->exists($recipe)) {
                        throw UnknownRecipe::referencedBy($dish, $recipe);
                    }

                    $checked[$recipe->value()] = true;
                }
            }
        }
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
     * @param  list<array{menu: int, lunch: string|null, dinner: string|null, lunch_recipe_id?: string|null, dinner_recipe_id?: string|null}>  $menus
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
                'lunch' => $menu['lunch'] === null ? null : self::withRecipe(Dish::described($menu['lunch']), $menu['lunch_recipe_id'] ?? null),
                'dinner' => $menu['dinner'] === null ? null : self::withRecipe(Dish::described($menu['dinner']), $menu['dinner_recipe_id'] ?? null),
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
     * @param  list<array{day: int, lunch: array{name: string, ingredients: list<array{name: string, quantity: string|null}>, recipe_id?: string|null}|null, dinner: array{name: string, ingredients: list<array{name: string, quantity: string|null}>, recipe_id?: string|null}|null}>  $days
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
     * @param  array{name: string, ingredients: list<array{name: string, quantity: string|null}>, recipe_id?: string|null}|null  $dish
     */
    private static function toNamedDish(?array $dish): ?Dish
    {
        if ($dish === null) {
            return null;
        }

        return self::withRecipe(
            Dish::named(
                $dish['name'],
                array_map(
                    fn (array $ingredient): DishIngredient => DishIngredient::of($ingredient['name'], $ingredient['quantity']),
                    $dish['ingredients'],
                ),
            ),
            $dish['recipe_id'] ?? null,
        );
    }

    /**
     * El plato, apuntando a su receta si trae una. Que el texto sea un UUID
     * lo dice RecipeReference (InvalidRecipeReference, en español); que la
     * receta exista se comprueba después, con el plan entero
     * (ensureRecipesExist).
     */
    private static function withRecipe(Dish $dish, ?string $recipeId): Dish
    {
        return $recipeId === null ? $dish : $dish->withRecipe(RecipeReference::fromString($recipeId));
    }
}
