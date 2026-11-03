<?php

declare(strict_types=1);

namespace MealPlanning\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use MealPlanning\Domain\Dish;
use MealPlanning\Domain\DishIngredient;
use MealPlanning\Domain\Exchange;
use MealPlanning\Domain\ExchangeCategory;
use MealPlanning\Domain\ExchangeTable;
use MealPlanning\Domain\FixedMeal;
use MealPlanning\Domain\MealOption;
use MealPlanning\Domain\MealType;
use MealPlanning\Domain\MenuDay;
use MealPlanning\Domain\MenuWeek;
use MealPlanning\Domain\NutritionPlan;
use MealPlanning\Domain\NutritionPlanId;
use MealPlanning\Domain\OptionGroup;
use MealPlanning\Domain\PlanNumber;
use MealPlanning\Domain\Weekday;
use MealPlanning\Domain\WeekSource;
use UnexpectedValueException;

/**
 * NutritionPlanMapper: traduce entre el agregado NutritionPlan y su fila de
 * nutrition_plans (tres columnas y tres documentos JSON).
 *
 *     NutritionPlan                     nutrition_plans
 *     ├── id, number, startsOn    ──►   id, number, starts_on
 *     ├── fixedMeals              ──►   fixed_meals     (jsonb, lista)
 *     ├── exchangeTable           ──►   exchange_table  (jsonb, objeto)
 *     └── weeks (1 y 2)           ──►   weeks           (jsonb, lista de 2)
 *            └── days → lunch/dinner (Dish) → ingredients
 *
 * CONCEPTO: Data Mapper (como Recipes\...\RecipeMapper, ADR 0006)
 * El dominio no sabe que existe una base de datos ni un formato JSON. Este
 * traductor conoce a los dos, y ninguno de los dos lo conoce a él. La forma
 * de los documentos es un DETALLE de persistencia: las claves están en
 * inglés y en snake_case, como las columnas, y no tienen por qué coincidir
 * con los métodos del dominio.
 *
 * CONCEPTO: el JSON guardado es una entrada NO confiable
 * Con tablas y columnas tipadas, PostgreSQL garantiza que `servings` es un
 * entero. Dentro de un jsonb, no: cualquier UPDATE a mano o una migración
 * mal hecha puede dejar un `"choose": "dos"` o quitar un campo. Por eso, al
 * leer, cada valor se comprueba (string(), int(), list()...) antes de dárselo
 * al dominio, y si algo no cuadra se lanza UnexpectedValueException con la
 * RUTA exacta del dato corrupto ("weeks[1].days[2].lunch.name"). Es lo mismo
 * que hace una Form Request con una petición HTTP, pero en el otro extremo:
 * un dato que entra en la aplicación desde fuera.
 *
 * CONCEPTO: reconstitución con los constructores del dominio (ADR 0006)
 * El plan se reconstruye con NutritionPlan::create(), MenuWeek::create(),
 * MenuDay::of(), Dish::named()/described()... Las invariantes se vuelven a
 * comprobar al cargar: un documento con cinco días o un sábado con menú no
 * se convierte en un plan inválido circulando por la aplicación, sino en un
 * InvalidMenuWeek al leerlo.
 *
 * CAPA: Infraestructura (MealPlanning\Infrastructure).
 */
final class NutritionPlanMapper
{
    // --- Agregado → fila ------------------------------------------------------

    /**
     * Agregado → columnas de nutrition_plans. Los documentos van como arrays
     * de PHP; el cast 'array' del modelo los convierte en JSON.
     *
     * @return array{id: string, number: int, starts_on: string, fixed_meals: list<array<string, mixed>>, exchange_table: array<string, mixed>, weeks: list<array<string, mixed>>}
     */
    public function toRow(NutritionPlan $plan): array
    {
        return [
            'id' => $plan->id()->value(),
            'number' => $plan->number()->value(),
            // Solo la fecha: el plan empieza un día, no a una hora.
            'starts_on' => $plan->startsOn()->format('Y-m-d'),
            'fixed_meals' => array_map(self::fixedMealToDocument(...), $plan->fixedMeals()),
            'exchange_table' => self::exchangeTableToDocument($plan->exchangeTable()),
            'weeks' => array_map(self::weekToDocument(...), $plan->weeks()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function fixedMealToDocument(FixedMeal $meal): array
    {
        return [
            'meal_type' => $meal->mealType()->value,
            'description' => $meal->description(),
            'option_groups' => array_map(fn (OptionGroup $group): array => [
                'choose' => $group->count(),
                'options' => array_map(fn (MealOption $option): string => $option->text(), $group->options()),
            ], $meal->optionGroups()),
        ];
    }

    /**
     * La tabla de intercambios agrupa por categoría (exchangesIn()). Se
     * guarda como una lista plana, categoría a categoría en el orden del
     * enum y, dentro de cada una, en el orden del documento: al leerla,
     * ExchangeTable::of() vuelve a agruparla igual.
     *
     * @return array<string, mixed>
     */
    private static function exchangeTableToDocument(ExchangeTable $table): array
    {
        $exchanges = [];

        foreach (ExchangeCategory::cases() as $category) {
            foreach ($table->exchangesIn($category) as $exchange) {
                $exchanges[] = [
                    'category' => $exchange->category()->value,
                    'food' => $exchange->food(),
                    'serving' => $exchange->serving(),
                ];
            }
        }

        return ['exchanges' => $exchanges, 'rules' => $table->rules()];
    }

    /**
     * @return array<string, mixed>
     */
    private static function weekToDocument(MenuWeek $week): array
    {
        return [
            'number' => $week->number(),
            'source' => $week->source()->value,
            // El día de la semana como número ISO (1 = lunes), el valor del
            // enum Weekday: compacto y sin ambigüedad de idioma.
            'days' => array_map(fn (MenuDay $day): array => [
                'weekday' => $day->weekday()->value,
                'lunch' => self::dishToDocument($day->lunch()),
                'dinner' => self::dishToDocument($day->dinner()),
            ], $week->days()),
        ];
    }

    /**
     * Los dos formatos de un plato (HEXM-0012) se guardan con la misma forma:
     * el de la semana 1 tiene `description` y el de la semana 2, `name` (e
     * ingredientes opcionales). El que no aplica va a null.
     *
     * @return array<string, mixed>
     */
    private static function dishToDocument(Dish $dish): array
    {
        return [
            'name' => $dish->name(),
            'description' => $dish->description(),
            'ingredients' => array_map(fn (DishIngredient $ingredient): array => [
                'name' => $ingredient->name(),
                'quantity' => $ingredient->quantity(),
            ], $dish->ingredients()),
        ];
    }

    // --- Fila → agregado ------------------------------------------------------

    public function toDomain(NutritionPlanModel $model): NutritionPlan
    {
        $fixedMeals = [];

        foreach (self::list($model->fixed_meals, 'fixed_meals') as $i => $meal) {
            $fixedMeals[] = self::fixedMealToDomain($meal, "fixed_meals[{$i}]");
        }

        $weeks = [];

        foreach (self::list($model->weeks, 'weeks') as $i => $week) {
            $weeks[] = self::weekToDomain($week, "weeks[{$i}]");
        }

        return NutritionPlan::create(
            NutritionPlanId::fromString($model->id),
            PlanNumber::of($model->number),
            new DateTimeImmutable($model->starts_on),
            $fixedMeals,
            self::exchangeTableToDomain($model->exchange_table, 'exchange_table'),
            $weeks,
        );
    }

    private static function fixedMealToDomain(mixed $document, string $path): FixedMeal
    {
        $meal = self::object($document, $path);
        $groups = [];

        foreach (self::list($meal['option_groups'] ?? null, "{$path}.option_groups") as $i => $group) {
            $groupPath = "{$path}.option_groups[{$i}]";
            $group = self::object($group, $groupPath);

            $groups[] = OptionGroup::choose(
                self::int($group['choose'] ?? null, "{$groupPath}.choose"),
                array_map(
                    fn (string $text): MealOption => MealOption::of($text),
                    self::strings($group['options'] ?? null, "{$groupPath}.options"),
                ),
            );
        }

        return FixedMeal::of(
            self::enum(MealType::class, $meal['meal_type'] ?? null, "{$path}.meal_type"),
            self::nullableString($meal['description'] ?? null, "{$path}.description"),
            $groups,
        );
    }

    private static function exchangeTableToDomain(mixed $document, string $path): ExchangeTable
    {
        $table = self::object($document, $path);
        $exchanges = [];

        foreach (self::list($table['exchanges'] ?? null, "{$path}.exchanges") as $i => $exchange) {
            $exchangePath = "{$path}.exchanges[{$i}]";
            $exchange = self::object($exchange, $exchangePath);

            $exchanges[] = Exchange::of(
                self::enum(ExchangeCategory::class, $exchange['category'] ?? null, "{$exchangePath}.category"),
                self::string($exchange['food'] ?? null, "{$exchangePath}.food"),
                self::string($exchange['serving'] ?? null, "{$exchangePath}.serving"),
            );
        }

        return ExchangeTable::of($exchanges, self::strings($table['rules'] ?? null, "{$path}.rules"));
    }

    private static function weekToDomain(mixed $document, string $path): MenuWeek
    {
        $week = self::object($document, $path);
        $days = [];

        foreach (self::list($week['days'] ?? null, "{$path}.days") as $i => $day) {
            $dayPath = "{$path}.days[{$i}]";
            $day = self::object($day, $dayPath);

            $days[] = MenuDay::of(
                self::enum(Weekday::class, $day['weekday'] ?? null, "{$dayPath}.weekday"),
                self::dishToDomain($day['lunch'] ?? null, "{$dayPath}.lunch"),
                self::dishToDomain($day['dinner'] ?? null, "{$dayPath}.dinner"),
            );
        }

        // MenuWeek::create() y no los constructores de formato
        // (fromNutritionistMenus/fromSelfDays): lo guardado ya son días de la
        // semana, no menús numerados. La traducción del formato se hizo una
        // vez, al crear el plan.
        return MenuWeek::create(
            self::int($week['number'] ?? null, "{$path}.number"),
            self::enum(WeekSource::class, $week['source'] ?? null, "{$path}.source"),
            $days,
        );
    }

    private static function dishToDomain(mixed $document, string $path): Dish
    {
        $dish = self::object($document, $path);
        $name = self::nullableString($dish['name'] ?? null, "{$path}.name");

        // Con nombre, es un plato de la semana 2 (nombre + ingredientes); si
        // no, uno de la semana 1 (descripción en texto libre).
        if ($name === null) {
            return Dish::described(self::string($dish['description'] ?? null, "{$path}.description"));
        }

        $ingredients = [];

        foreach (self::list($dish['ingredients'] ?? null, "{$path}.ingredients") as $i => $ingredient) {
            $ingredientPath = "{$path}.ingredients[{$i}]";
            $ingredient = self::object($ingredient, $ingredientPath);

            $ingredients[] = DishIngredient::of(
                self::string($ingredient['name'] ?? null, "{$ingredientPath}.name"),
                self::nullableString($ingredient['quantity'] ?? null, "{$ingredientPath}.quantity"),
            );
        }

        return Dish::named($name, $ingredients);
    }

    // --- Lectura defensiva del JSON -------------------------------------------
    //
    // CONCEPTO: narrowing de tipos (estrechar un mixed)
    // json_decode devuelve `mixed`: PHPStan (y nosotros) no sabemos qué hay.
    // Cada función comprueba el tipo en tiempo de ejecución y devuelve un
    // tipo concreto, o lanza la excepción con la ruta del dato. A partir de
    // ahí, el resto del código trabaja con tipos seguros.

    /**
     * Una lista JSON ([...]): claves 0, 1, 2... sin huecos.
     *
     * @return list<mixed>
     */
    private static function list(mixed $value, string $path): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw self::corrupted($path, 'una lista');
        }

        return $value;
    }

    /**
     * Un objeto JSON ({...}), que PHP decodifica como array asociativo.
     *
     * @return array<string, mixed>
     */
    private static function object(mixed $value, string $path): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw self::corrupted($path, 'un objeto');
        }

        $object = [];

        foreach ($value as $key => $item) {
            $object[(string) $key] = $item;
        }

        return $object;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value, string $path): array
    {
        $strings = [];

        foreach (self::list($value, $path) as $i => $item) {
            $strings[] = self::string($item, "{$path}[{$i}]");
        }

        return $strings;
    }

    private static function string(mixed $value, string $path): string
    {
        if (! is_string($value)) {
            throw self::corrupted($path, 'un texto');
        }

        return $value;
    }

    private static function nullableString(mixed $value, string $path): ?string
    {
        return $value === null ? null : self::string($value, $path);
    }

    private static function int(mixed $value, string $path): int
    {
        if (! is_int($value)) {
            throw self::corrupted($path, 'un número entero');
        }

        return $value;
    }

    /**
     * Un caso de un enum respaldado (backed enum), a partir de su valor.
     *
     * CONCEPTO: genéricos en PHPDoc (@template)
     * PHP no tiene genéricos, pero PHPStan sí los entiende en los comentarios:
     * con enum(MealType::class, ...), sabe que lo que vuelve es un MealType.
     *
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T
     */
    private static function enum(string $enum, mixed $value, string $path): \BackedEnum
    {
        // tryFrom() devuelve null si el valor no es un caso del enum (from()
        // lanzaría un ValueError sin decir qué dato era).
        $case = is_int($value) || is_string($value) ? $enum::tryFrom($value) : null;

        if ($case === null) {
            throw self::corrupted($path, "un valor de {$enum}");
        }

        return $case;
    }

    /**
     * No es un error del usuario ni una regla de negocio: son datos que
     * nunca debieron llegar a la base de datos. Por eso no es una
     * DomainException (que la API mostraría como 422), sino una excepción
     * técnica.
     */
    private static function corrupted(string $path, string $expected): UnexpectedValueException
    {
        return new UnexpectedValueException("Plan guardado corrupto: «{$path}» debería ser {$expected}.");
    }
}
