<?php

declare(strict_types=1);

namespace MealPlanning\Infrastructure\Http;

use DateTimeImmutable;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;
use MealPlanning\Application\RegisterNutritionPlanCommand;

/**
 * RegisterNutritionPlanRequest: valida la FORMA de POST /api/nutrition-plans
 * y la traduce al comando del caso de uso.
 *
 * CONCEPTO: validación de forma frente a reglas de negocio (ADR 0007)
 * Igual que CreateRecipeRequest: aquí solo se comprueba el CONTRATO de la API
 * (que estén los campos y que tengan el tipo esperado: number es un entero,
 * starts_on una fecha AAAA-MM-DD, nutritionist_week una lista...). Las reglas
 * del negocio NO se repiten aquí:
 *   - que el plan empiece en lunes, que haya 6 días por semana, que el
 *     sábado no tenga menú o que un "elige N" no pida más opciones de las que
 *     hay lo dice el dominio (422 con su mensaje en español);
 *   - que meal_type sea "breakfast" o category "protein" tampoco se valida
 *     con `in:`: lo traduce el caso de uso, con un error del dominio. Así el
 *     importador del nivel 7, que no pasa por aquí, recibe el mismo mensaje.
 * Por eso los textos son `nullable` (un plato vacío o sin nombre es una
 * regla, no un problema de forma) y las listas no exigen un mínimo.
 *
 * Lo mismo con la receta de un plato (HEXM-0025, ADR 0017): recipe_id (semana
 * 2) y lunch_recipe_id / dinner_recipe_id (semana 1, claves hermanas del
 * texto del plato) solo se validan como texto opcional. Ni la regla `uuid`
 * ni `exists:recipes,id`: que sea un id válido lo dice el dominio
 * (InvalidRecipeReference) y que la receta exista, el puerto RecipeCatalog
 * (UnknownRecipe). Un `exists:` además haría que MealPlanning consultara la
 * tabla de OTRO contexto, saltándose su capa de aplicación.
 *
 * Con un payload grande, esta frontera se nota: una petición mal formada
 * recibe la lista de TODOS sus errores de forma, con la ruta de cada uno
 * ("self_week.2.lunch.ingredients.0.quantity"), sin llegar al caso de uso.
 *
 * CAPA: Infraestructura (MealPlanning\Infrastructure\Http): adaptador driving.
 */
final class RegisterNutritionPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Las reglas de los platos de la semana 2 se repiten para la comida y la
     * cena; se generan con dishRules() para no escribirlas dos veces.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'number' => ['required', 'integer'],
            // date_format y no date: el contrato es AAAA-MM-DD exacto, sin
            // horas ni zonas (un plan empieza un día del calendario).
            'starts_on' => ['required', 'date_format:Y-m-d'],

            'fixed_meals' => ['present', 'array', 'list'],
            'fixed_meals.*' => ['array'],
            'fixed_meals.*.meal_type' => ['required', 'string'],
            'fixed_meals.*.description' => ['nullable', 'string'],
            'fixed_meals.*.option_groups' => ['sometimes', 'array', 'list'],
            'fixed_meals.*.option_groups.*' => ['array'],
            'fixed_meals.*.option_groups.*.choose' => ['required', 'integer'],
            'fixed_meals.*.option_groups.*.options' => ['present', 'array', 'list'],
            'fixed_meals.*.option_groups.*.options.*' => ['nullable', 'string'],

            'exchanges' => ['present', 'array', 'list'],
            'exchanges.*' => ['array'],
            'exchanges.*.category' => ['required', 'string'],
            'exchanges.*.food' => ['nullable', 'string'],
            'exchanges.*.serving' => ['nullable', 'string'],
            'exchange_rules' => ['sometimes', 'array', 'list'],
            'exchange_rules.*' => ['nullable', 'string'],

            'nutritionist_week' => ['present', 'array', 'list'],
            'nutritionist_week.*' => ['array'],
            'nutritionist_week.*.menu' => ['required', 'integer'],
            'nutritionist_week.*.lunch' => ['nullable', 'string'],
            'nutritionist_week.*.dinner' => ['nullable', 'string'],
            'nutritionist_week.*.lunch_recipe_id' => ['nullable', 'string'],
            'nutritionist_week.*.dinner_recipe_id' => ['nullable', 'string'],

            'self_week' => ['present', 'array', 'list'],
            'self_week.*' => ['array'],
            'self_week.*.day' => ['required', 'integer'],
            ...self::dishRules('self_week.*.lunch'),
            ...self::dishRules('self_week.*.dinner'),
        ];
    }

    /**
     * Un plato de la semana 2: un objeto {name, ingredients, recipe_id}, o
     * null si el día está vacío (el 13).
     *
     * @return array<string, list<string>>
     */
    private static function dishRules(string $dish): array
    {
        return [
            $dish => ['nullable', 'array'],
            "{$dish}.name" => ['nullable', 'string'],
            "{$dish}.recipe_id" => ['nullable', 'string'],
            "{$dish}.ingredients" => ['sometimes', 'array', 'list'],
            "{$dish}.ingredients.*" => ['array'],
            "{$dish}.ingredients.*.name" => ['nullable', 'string'],
            "{$dish}.ingredients.*.quantity" => ['nullable', 'string'],
        ];
    }

    /**
     * CONCEPTO: traducción de la frontera al comando
     * Después de validar, se sabe la FORMA de los datos (y se le cuenta a
     * PHPStan con @var), pero los opcionales pueden faltar y los textos ser
     * null. Aquí se rellenan los huecos de forma neutra (null → '' para que
     * el dominio diga "no puede estar vacío"; listas ausentes → []) y se
     * convierte starts_on en DateTimeImmutable.
     */
    public function toCommand(): RegisterNutritionPlanCommand
    {
        /** @var array{number: int|string, starts_on: string, fixed_meals: list<array{meal_type: string, description?: string|null, option_groups?: list<array{choose: int|string, options: list<string|null>}>}>, exchanges: list<array{category: string, food?: string|null, serving?: string|null}>, exchange_rules?: list<string|null>, nutritionist_week: list<array{menu: int|string, lunch?: string|null, dinner?: string|null, lunch_recipe_id?: string|null, dinner_recipe_id?: string|null}>, self_week: list<array{day: int|string, lunch?: array{name?: string|null, ingredients?: list<array{name?: string|null, quantity?: string|null}>, recipe_id?: string|null}|null, dinner?: array{name?: string|null, ingredients?: list<array{name?: string|null, quantity?: string|null}>, recipe_id?: string|null}|null}>} $data */
        $data = $this->validated();

        return new RegisterNutritionPlanCommand(
            // integer acepta 27 y "27": se convierte.
            number: (int) $data['number'],
            // '!' pone a cero la hora: la fecha a medianoche, sin la hora
            // actual que createFromFormat() rellenaría por defecto.
            startsOn: DateTimeImmutable::createFromFormat('!Y-m-d', $data['starts_on'])
                ?: throw new LogicException('date_format:Y-m-d ya ha validado la fecha.'),
            fixedMeals: array_map(fn (array $meal): array => [
                'meal_type' => $meal['meal_type'],
                'description' => $meal['description'] ?? null,
                'option_groups' => array_map(fn (array $group): array => [
                    'choose' => (int) $group['choose'],
                    'options' => array_map(fn (?string $option): string => $option ?? '', $group['options']),
                ], $meal['option_groups'] ?? []),
            ], $data['fixed_meals']),
            exchanges: array_map(fn (array $exchange): array => [
                'category' => $exchange['category'],
                'food' => $exchange['food'] ?? '',
                'serving' => $exchange['serving'] ?? '',
            ], $data['exchanges']),
            exchangeRules: array_map(fn (?string $rule): string => $rule ?? '', $data['exchange_rules'] ?? []),
            nutritionistWeek: array_map(fn (array $menu): array => [
                'menu' => (int) $menu['menu'],
                'lunch' => $menu['lunch'] ?? null,
                'dinner' => $menu['dinner'] ?? null,
                'lunch_recipe_id' => $menu['lunch_recipe_id'] ?? null,
                'dinner_recipe_id' => $menu['dinner_recipe_id'] ?? null,
            ], $data['nutritionist_week']),
            selfWeek: array_map(fn (array $day): array => [
                'day' => (int) $day['day'],
                'lunch' => self::toDish($day['lunch'] ?? null),
                'dinner' => self::toDish($day['dinner'] ?? null),
            ], $data['self_week']),
        );
    }

    /**
     * @param  array{name?: string|null, ingredients?: list<array{name?: string|null, quantity?: string|null}>, recipe_id?: string|null}|null  $dish
     * @return array{name: string, ingredients: list<array{name: string, quantity: string|null}>, recipe_id: string|null}|null
     */
    private static function toDish(?array $dish): ?array
    {
        if ($dish === null) {
            return null;
        }

        return [
            'name' => $dish['name'] ?? '',
            'ingredients' => array_map(fn (array $ingredient): array => [
                'name' => $ingredient['name'] ?? '',
                'quantity' => $ingredient['quantity'] ?? null,
            ], $dish['ingredients'] ?? []),
            'recipe_id' => $dish['recipe_id'] ?? null,
        ];
    }
}
