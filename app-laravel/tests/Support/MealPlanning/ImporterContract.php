<?php

declare(strict_types=1);

namespace Tests\Support\MealPlanning;

use MealPlanning\Application\NutritionistPlanReading;
use MealPlanning\Application\SelfWeekReading;

/**
 * ImporterContract: acceso desde los tests al contrato con el servicio
 * importer, versionado en contracts/importer (HEXM-0031, ADR 0024).
 *
 * El contrato lo genera el propio servicio (`uv run poe contract`): el JSON
 * Schema de cada respuesta, sacado de los modelos de Pydantic, y respuestas
 * reales con los Excel ficticios. Los tests de Laravel lo usan como fuente
 * de verdad en vez de escribir respuestas a mano: si el servicio cambia su
 * JSON, cambian estos ficheros y los tests de Laravel dicen si lo siguen
 * entendiendo.
 *
 * CAPA: tests (apoyo).
 */
final class ImporterContract
{
    public const string NUTRITIONIST_PLAN = 'nutritionist-plan';

    public const string SELF_WEEK = 'self-week';

    public const string INVALID_WORKBOOK = 'invalid-workbook';

    /**
     * contracts/importer, en la raíz del repositorio (fuera de app-laravel:
     * es de los dos lados).
     */
    public static function directory(): string
    {
        return dirname(__DIR__, 4).'/contracts/importer';
    }

    /**
     * El cuerpo de una respuesta de ejemplo, tal como lo envía el servicio.
     */
    public static function exampleBody(string $name): string
    {
        $body = file_get_contents(self::directory()."/examples/{$name}.json");
        assert(is_string($body), "No existe el ejemplo {$name} del contrato.");

        return $body;
    }

    /**
     * Una respuesta de ejemplo, como array (lo que daría $response->json()).
     *
     * @return array<string, mixed>
     */
    public static function example(string $name): array
    {
        /** @var array<string, mixed> */
        return json_decode(self::exampleBody($name), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * El JSON Schema de una respuesta, como texto (lo que espera el validador).
     */
    public static function schema(string $name): string
    {
        $schema = file_get_contents(self::directory()."/{$name}.schema.json");
        assert(is_string($schema), "No existe el schema {$name} del contrato.");

        return $schema;
    }

    /**
     * La lectura que corresponde al ejemplo del Excel de la nutricionista,
     * construida directamente con sus valores (sin pasar por el mapper del
     * adaptador, que es lo que se quiere comprobar).
     */
    public static function nutritionistPlanReading(): NutritionistPlanReading
    {
        /** @var array{number: int, fixed_meals: list<array{meal_type: string, description: string|null, option_groups: list<array{choose: int, options: list<string>}>}>, exchanges: list<array{category: string, food: string, serving: string}>, exchange_rules: list<string>, nutritionist_week: list<array{menu: int, lunch: string, dinner: string}>} $plan */
        $plan = self::example(self::NUTRITIONIST_PLAN);

        return new NutritionistPlanReading(
            $plan['number'],
            $plan['fixed_meals'],
            $plan['exchanges'],
            $plan['exchange_rules'],
            $plan['nutritionist_week'],
        );
    }

    /**
     * La lectura que corresponde al ejemplo del Excel de la semana 2.
     */
    public static function selfWeekReading(): SelfWeekReading
    {
        /** @var array{self_week: list<array{day: int, lunch: array{name: string, ingredients: list<array{name: string, quantity: string|null}>}|null, dinner: array{name: string, ingredients: list<array{name: string, quantity: string|null}>}|null}>} $week */
        $week = self::example(self::SELF_WEEK);

        return new SelfWeekReading($week['self_week']);
    }

    /**
     * Los problemas del ejemplo de 422, en el orden en que los da el servicio.
     *
     * @return list<string>
     */
    public static function problems(): array
    {
        /** @var array{errors: list<array{message: string}>} $body */
        $body = self::example(self::INVALID_WORKBOOK);

        return array_column($body['errors'], 'message');
    }
}
