<?php

declare(strict_types=1);

namespace MealPlanning\Infrastructure\Importer;

use MealPlanning\Application\NutritionistPlanReading;
use MealPlanning\Application\SelfWeekReading;

/**
 * ImporterResponse: traduce el JSON del servicio importer a las lecturas del
 * puerto PlanReader, comprobando por el camino que cumple el contrato.
 *
 * CONCEPTO: mapper en el borde (como NutritionPlanMapper con el jsonb)
 * Lo que llega por la red es `mixed`: el adaptador no puede fiarse de que el
 * JSON tenga la forma acordada solo porque el otro servicio «debería»
 * enviarla así. Este mapper lo recorre campo a campo y construye los DTO
 * del núcleo con tipos de verdad. Si algo no cuadra, lanza
 * InvalidImporterResponse con la ruta exacta del problema.
 *
 * DECISIÓN: estricto, como el contrato (ADR 0024)
 * Los modelos de Pydantic prohíben los campos de más (extra="forbid") y el
 * JSON Schema lo refleja ("additionalProperties": false). Aquí se aplica lo
 * mismo: falta un campo, sobra uno o cambia un tipo → error. Un mapper
 * permisivo (que ignorase lo que no conoce) dejaría pasar en silencio un
 * cambio del contrato, que es justo lo que los tests de contrato quieren
 * detectar.
 *
 * Qué NO comprueba: las reglas del negocio (seis menús, el sábado vacío...)
 * ni los mínimos del schema (textos no vacíos, días entre 8 y 14). Lo
 * primero lo hace el dominio al dar de alta el plan, venga de donde venga;
 * lo segundo lo garantiza el servicio con Pydantic, y si un día no lo
 * hiciera, lo pararía igualmente el dominio con su mensaje en español.
 *
 * DECISIÓN: sin librería de JSON Schema en producción
 * Se podría validar cada respuesta contra el schema versionado
 * (opis/json-schema, que ya se usa en los tests). Pero el adaptador necesita
 * igualmente recorrer el JSON para construir los DTO, y comprobar los tipos
 * mientras lo hace es poco código más. La librería queda como dependencia de
 * desarrollo, para los tests de contrato.
 *
 * CAPA: Infraestructura (MealPlanning\Infrastructure\Importer).
 */
final class ImporterResponse
{
    /**
     * La respuesta de POST /nutritionist-plans/read.
     */
    public static function nutritionistPlan(mixed $json): NutritionistPlanReading
    {
        $plan = self::object($json, '$', ['number', 'fixed_meals', 'exchanges', 'exchange_rules', 'nutritionist_week']);

        return new NutritionistPlanReading(
            number: self::int($plan['number'], 'number'),
            fixedMeals: self::map($plan['fixed_meals'], 'fixed_meals', function (mixed $value, string $path): array {
                $meal = self::object($value, $path, ['meal_type', 'description', 'option_groups']);

                return [
                    'meal_type' => self::string($meal['meal_type'], "{$path}.meal_type"),
                    'description' => self::nullableString($meal['description'], "{$path}.description"),
                    'option_groups' => self::map($meal['option_groups'], "{$path}.option_groups", function (mixed $value, string $path): array {
                        $group = self::object($value, $path, ['choose', 'options']);

                        return [
                            'choose' => self::int($group['choose'], "{$path}.choose"),
                            'options' => self::map($group['options'], "{$path}.options", self::string(...)),
                        ];
                    }),
                ];
            }),
            exchanges: self::map($plan['exchanges'], 'exchanges', function (mixed $value, string $path): array {
                $exchange = self::object($value, $path, ['category', 'food', 'serving']);

                return [
                    'category' => self::string($exchange['category'], "{$path}.category"),
                    'food' => self::string($exchange['food'], "{$path}.food"),
                    'serving' => self::string($exchange['serving'], "{$path}.serving"),
                ];
            }),
            exchangeRules: self::map($plan['exchange_rules'], 'exchange_rules', self::string(...)),
            nutritionistWeek: self::map($plan['nutritionist_week'], 'nutritionist_week', function (mixed $value, string $path): array {
                $menu = self::object($value, $path, ['menu', 'lunch', 'dinner']);

                return [
                    'menu' => self::int($menu['menu'], "{$path}.menu"),
                    'lunch' => self::string($menu['lunch'], "{$path}.lunch"),
                    'dinner' => self::string($menu['dinner'], "{$path}.dinner"),
                ];
            }),
        );
    }

    /**
     * La respuesta de POST /self-weeks/read.
     */
    public static function selfWeek(mixed $json): SelfWeekReading
    {
        $week = self::object($json, '$', ['self_week']);

        return new SelfWeekReading(
            self::map($week['self_week'], 'self_week', function (mixed $value, string $path): array {
                $day = self::object($value, $path, ['day', 'lunch', 'dinner']);

                return [
                    'day' => self::int($day['day'], "{$path}.day"),
                    'lunch' => self::selfDish($day['lunch'], "{$path}.lunch"),
                    'dinner' => self::selfDish($day['dinner'], "{$path}.dinner"),
                ];
            }),
        );
    }

    /**
     * Los problemas de un 422 del servicio (un Excel mal formado), cada uno
     * con su texto en español, que ya incluye la hoja y la celda.
     *
     * @return list<string>
     */
    public static function problems(mixed $json): array
    {
        $body = self::object($json, '$', ['message', 'errors']);
        $problems = self::map($body['errors'], 'errors', function (mixed $value, string $path): string {
            $problem = self::object($value, $path, ['sheet', 'cell', 'message']);

            return self::string($problem['message'], "{$path}.message");
        });

        if ($problems === []) {
            throw InvalidImporterResponse::at('errors', 'un 422 debía traer al menos un problema');
        }

        return $problems;
    }

    /**
     * Un plato de la semana 2, o null (el sábado).
     *
     * @return array{name: string, ingredients: list<array{name: string, quantity: string|null}>}|null
     */
    private static function selfDish(mixed $value, string $path): ?array
    {
        if ($value === null) {
            return null;
        }

        $dish = self::object($value, $path, ['name', 'ingredients']);

        return [
            'name' => self::string($dish['name'], "{$path}.name"),
            'ingredients' => self::map($dish['ingredients'], "{$path}.ingredients", function (mixed $value, string $path): array {
                $ingredient = self::object($value, $path, ['name', 'quantity']);

                return [
                    'name' => self::string($ingredient['name'], "{$path}.name"),
                    'quantity' => self::nullableString($ingredient['quantity'], "{$path}.quantity"),
                ];
            }),
        ];
    }

    // --- Comprobaciones de forma -------------------------------------------

    /**
     * Un objeto JSON con EXACTAMENTE esas claves.
     *
     * json_decode(…, true) convierte un objeto en un array asociativo; uno
     * vacío ({}) queda como [], indistinguible de una lista vacía, y por eso
     * [] se admite aquí (y fallará después por las claves que le faltan).
     *
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private static function object(mixed $value, string $path, array $keys): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw InvalidImporterResponse::at($path, 'debía ser un objeto');
        }

        $actual = array_map(strval(...), array_keys($value));
        $missing = array_diff($keys, $actual);
        $unexpected = array_diff($actual, $keys);

        if ($missing !== []) {
            throw InvalidImporterResponse::at($path, 'faltan los campos '.implode(', ', $missing));
        }

        if ($unexpected !== []) {
            throw InvalidImporterResponse::at($path, 'sobran los campos '.implode(', ', $unexpected));
        }

        /** @var array<string, mixed> $value las claves son exactamente $keys */
        return $value;
    }

    /**
     * Una lista JSON, con cada elemento traducido por $item (que recibe el
     * elemento y su ruta, «exchanges.3»).
     *
     * CONCEPTO: genéricos de PHPStan (@template)
     * PHP no tiene genéricos, pero PHPStan sí los entiende en los docblocks:
     * map() devuelve una lista de lo que devuelva $item. Así cada llamada
     * conserva su tipo exacto (list<string>, list<array{…}>) sin repetirlo.
     *
     * @template T
     *
     * @param  callable(mixed, string): T  $item
     * @return list<T>
     */
    private static function map(mixed $value, string $path, callable $item): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw InvalidImporterResponse::at($path, 'debía ser una lista');
        }

        return array_map(fn (mixed $element, int $index): mixed => $item($element, "{$path}.{$index}"), $value, array_keys($value));
    }

    private static function string(mixed $value, string $path): string
    {
        if (! is_string($value)) {
            throw InvalidImporterResponse::at($path, 'debía ser un texto');
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
            throw InvalidImporterResponse::at($path, 'debía ser un número entero');
        }

        return $value;
    }
}
