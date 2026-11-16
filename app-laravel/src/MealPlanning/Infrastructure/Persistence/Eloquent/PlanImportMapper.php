<?php

declare(strict_types=1);

namespace MealPlanning\Infrastructure\Persistence\Eloquent;

use MealPlanning\Domain\ImportedNutritionistPlan;
use MealPlanning\Domain\ImportedSelfWeek;
use MealPlanning\Domain\NutritionPlanId;
use MealPlanning\Domain\PlanImport;
use MealPlanning\Domain\PlanImportId;
use MealPlanning\Domain\PlanNumber;

/**
 * PlanImportMapper: traduce entre el agregado PlanImport y su fila de
 * plan_imports.
 *
 *     PlanImport                         plan_imports
 *     ├── id, status()             ──►   id, status
 *     ├── ImportedNutritionistPlan ──►   nutritionist_plan  (jsonb, objeto)
 *     ├── ImportedSelfWeek | null  ──►   self_week          (jsonb, lista | NULL)
 *     └── plan() | null            ──►   nutrition_plan_id  (uuid | NULL)
 *
 * CONCEPTO: Data Mapper (como NutritionPlanMapper, ADR 0006 y 0013)
 * Los documentos tienen la misma forma que el contrato del alta de un plan
 * (ADR 0014), que es la que ya tienen los value objects del dominio: guardar
 * es copiar. Leer, no: el JSON guardado es una entrada no confiable, y cada
 * valor se comprueba con las funciones del trait ReadsStoredJson antes de
 * dárselo al dominio (con la ruta del dato si algo no cuadra).
 *
 * La importación se reconstruye con PlanImport::create(), que vuelve a
 * comprobar sus invariantes (una confirmada tiene la semana 2).
 *
 * CAPA: Infraestructura (MealPlanning\Infrastructure).
 */
final class PlanImportMapper
{
    use ReadsStoredJson;

    // --- Agregado → fila ------------------------------------------------------

    /**
     * @return array{id: string, status: string, nutritionist_plan: array<string, mixed>, self_week: list<array<string, mixed>>|null, nutrition_plan_id: string|null}
     */
    public function toRow(PlanImport $import): array
    {
        $nutritionistPlan = $import->nutritionistPlan();

        return [
            'id' => $import->id()->value(),
            // El estado, deducido en el dominio; la tabla lo guarda para
            // leerla y comprobarla con un CHECK (ver la migración).
            'status' => $import->status()->value,
            'nutritionist_plan' => [
                'number' => $nutritionistPlan->number()->value(),
                'fixed_meals' => $nutritionistPlan->fixedMeals(),
                'exchanges' => $nutritionistPlan->exchanges(),
                'exchange_rules' => $nutritionistPlan->exchangeRules(),
                'nutritionist_week' => $nutritionistPlan->nutritionistWeek(),
            ],
            'self_week' => $import->selfWeek()?->days(),
            'nutrition_plan_id' => $import->plan()?->value(),
        ];
    }

    // --- Fila → agregado ------------------------------------------------------

    public function toDomain(PlanImportModel $model): PlanImport
    {
        return PlanImport::create(
            PlanImportId::fromString($model->id),
            self::nutritionistPlanToDomain($model->nutritionist_plan, 'nutritionist_plan'),
            $model->self_week === null ? null : self::selfWeekToDomain($model->self_week, 'self_week'),
            $model->nutrition_plan_id === null ? null : NutritionPlanId::fromString($model->nutrition_plan_id),
        );
    }

    private static function nutritionistPlanToDomain(mixed $document, string $path): ImportedNutritionistPlan
    {
        $plan = self::object($document, $path);

        $fixedMeals = [];

        foreach (self::list($plan['fixed_meals'] ?? null, "{$path}.fixed_meals") as $i => $meal) {
            $mealPath = "{$path}.fixed_meals[{$i}]";
            $meal = self::object($meal, $mealPath);
            $groups = [];

            foreach (self::list($meal['option_groups'] ?? null, "{$mealPath}.option_groups") as $j => $group) {
                $groupPath = "{$mealPath}.option_groups[{$j}]";
                $group = self::object($group, $groupPath);
                $groups[] = [
                    'choose' => self::int($group['choose'] ?? null, "{$groupPath}.choose"),
                    'options' => self::strings($group['options'] ?? null, "{$groupPath}.options"),
                ];
            }

            $fixedMeals[] = [
                'meal_type' => self::string($meal['meal_type'] ?? null, "{$mealPath}.meal_type"),
                'description' => self::nullableString($meal['description'] ?? null, "{$mealPath}.description"),
                'option_groups' => $groups,
            ];
        }

        $exchanges = [];

        foreach (self::list($plan['exchanges'] ?? null, "{$path}.exchanges") as $i => $exchange) {
            $exchangePath = "{$path}.exchanges[{$i}]";
            $exchange = self::object($exchange, $exchangePath);
            $exchanges[] = [
                'category' => self::string($exchange['category'] ?? null, "{$exchangePath}.category"),
                'food' => self::string($exchange['food'] ?? null, "{$exchangePath}.food"),
                'serving' => self::string($exchange['serving'] ?? null, "{$exchangePath}.serving"),
            ];
        }

        $menus = [];

        foreach (self::list($plan['nutritionist_week'] ?? null, "{$path}.nutritionist_week") as $i => $menu) {
            $menuPath = "{$path}.nutritionist_week[{$i}]";
            $menu = self::object($menu, $menuPath);
            $menus[] = [
                'menu' => self::int($menu['menu'] ?? null, "{$menuPath}.menu"),
                'lunch' => self::string($menu['lunch'] ?? null, "{$menuPath}.lunch"),
                'dinner' => self::string($menu['dinner'] ?? null, "{$menuPath}.dinner"),
            ];
        }

        return ImportedNutritionistPlan::of(
            PlanNumber::of(self::int($plan['number'] ?? null, "{$path}.number")),
            $fixedMeals,
            $exchanges,
            self::strings($plan['exchange_rules'] ?? null, "{$path}.exchange_rules"),
            $menus,
        );
    }

    private static function selfWeekToDomain(mixed $document, string $path): ImportedSelfWeek
    {
        $days = [];

        foreach (self::list($document, $path) as $i => $day) {
            $dayPath = "{$path}[{$i}]";
            $day = self::object($day, $dayPath);
            $days[] = [
                'day' => self::int($day['day'] ?? null, "{$dayPath}.day"),
                'lunch' => self::dishToDomain($day['lunch'] ?? null, "{$dayPath}.lunch"),
                'dinner' => self::dishToDomain($day['dinner'] ?? null, "{$dayPath}.dinner"),
            ];
        }

        return ImportedSelfWeek::of($days);
    }

    /**
     * Un plato de la semana 2 (nombre e ingredientes), o null (el día 13).
     *
     * @return array{name: string, ingredients: list<array{name: string, quantity: string|null}>}|null
     */
    private static function dishToDomain(mixed $document, string $path): ?array
    {
        if ($document === null) {
            return null;
        }

        $dish = self::object($document, $path);
        $ingredients = [];

        foreach (self::list($dish['ingredients'] ?? null, "{$path}.ingredients") as $i => $ingredient) {
            $ingredientPath = "{$path}.ingredients[{$i}]";
            $ingredient = self::object($ingredient, $ingredientPath);
            $ingredients[] = [
                'name' => self::string($ingredient['name'] ?? null, "{$ingredientPath}.name"),
                'quantity' => self::nullableString($ingredient['quantity'] ?? null, "{$ingredientPath}.quantity"),
            ];
        }

        return [
            'name' => self::string($dish['name'] ?? null, "{$path}.name"),
            'ingredients' => $ingredients,
        ];
    }

    private static function corruptionPrefix(): string
    {
        return 'Importación guardada corrupta';
    }
}
