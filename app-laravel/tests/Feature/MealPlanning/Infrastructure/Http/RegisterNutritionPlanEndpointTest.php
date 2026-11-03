<?php

/*
| Tests de POST /api/nutrition-plans: el alta manual de un plan, de punta a
| punta (rutas → Form Request → controlador → caso de uso → dominio →
| EloquentNutritionPlanRepository → PostgreSQL).
|
| Como los del alta de recetas (HEXM-0010), siguen los tres caminos de una
| petición:
|   1. plan válido                → 201 y el plan guardado, completo;
|   2. petición mal formada       → 422 de la VALIDACIÓN de Laravel
|                                   ({"message", "errors"}), sin llegar al
|                                   caso de uso;
|   3. incumple una regla         → 422 del DOMINIO ({"message"} en español).
|
| El plan de partida es tests/fixtures/nutrition-plan-27.json (ficticio):
| el mismo fichero que se usa en las pruebas manuales con curl.
*/

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use MealPlanning\Domain\MealType;
use MealPlanning\Domain\NutritionPlanId;
use MealPlanning\Domain\NutritionPlanRepository;
use MealPlanning\Domain\Weekday;

uses(RefreshDatabase::class);

/**
 * El plan 27 del fixture, con cambios opcionales. Cada test estropea UNA
 * cosa: Arr::set admite claves con puntos ("self_week.2.lunch.name");
 * Arr::forget quita una clave (valor especial FORGET).
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function planPayload(array $changes = []): array
{
    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) file_get_contents(base_path('tests/fixtures/nutrition-plan-27.json')), true, flags: JSON_THROW_ON_ERROR);

    foreach ($changes as $key => $value) {
        $value === 'FORGET' ? Arr::forget($payload, $key) : Arr::set($payload, $key, $value);
    }

    return $payload;
}

// ---------------------------------------------------------------------------
// 1. Plan válido
// ---------------------------------------------------------------------------

it('registers a plan and returns its id', function () {
    $response = $this->postJson('/api/nutrition-plans', planPayload());

    $response->assertCreated()->assertJsonStructure(['id']);

    $id = $response->json('id');
    expect($id)->toBeString();

    // Leído de PostgreSQL a través del puerto, con el binding real.
    $plan = app(NutritionPlanRepository::class)->find(NutritionPlanId::fromString($id));

    expect($plan?->number()->value())->toBe(27)
        ->and($plan?->startsOn()->format('Y-m-d'))->toBe('2026-11-02')
        // El menú 6 de la nutricionista es el domingo de la semana 1.
        ->and($plan?->week(1)->day(Weekday::Sunday)?->lunch()->description())->toBe('Guiso de alubias y pollo')
        // El día 10 del usuario es el miércoles de la semana 2.
        ->and($plan?->week(2)->day(Weekday::Wednesday)?->lunch()->name())->toBe('Pollo al curry con arroz')
        ->and($plan?->week(2)->day(Weekday::Wednesday)?->lunch()->ingredients())->toHaveCount(2)
        ->and($plan?->fixedMeal(MealType::MidMorning)?->optionGroups()[0]->count())->toBe(2)
        ->and($plan?->exchangeTable()->rules())->toHaveCount(2);
});

it('accepts the free day 13 missing from the self week', function () {
    $payload = planPayload();
    $payload['self_week'] = array_values(array_filter($payload['self_week'], fn (array $day) => $day['day'] !== 13));

    $this->postJson('/api/nutrition-plans', $payload)->assertCreated();
});

// ---------------------------------------------------------------------------
// 2. Petición mal formada: la validación de Laravel, con la ruta del campo
// ---------------------------------------------------------------------------

it('rejects a malformed request before reaching the use case', function (array $changes, string $field) {
    $this->postJson('/api/nutrition-plans', planPayload($changes))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    // No ha llegado al caso de uso: no se ha guardado nada.
    expect(DB::table('nutrition_plans')->count())->toBe(0);
})->with([
    'without number' => [['number' => 'FORGET'], 'number'],
    'number is not an integer' => [['number' => 'veintisiete'], 'number'],
    'starts_on is not a date' => [['starts_on' => '02/11/2026'], 'starts_on'],
    'nutritionist_week is not a list' => [['nutritionist_week' => 'lunes'], 'nutritionist_week'],
    'a menu without number' => [['nutritionist_week.0.menu' => 'FORGET'], 'nutritionist_week.0.menu'],
    'a dish that is not text' => [['nutritionist_week.2.lunch' => ['Lentejas']], 'nutritionist_week.2.lunch'],
    'a quantity that is not text' => [['self_week.0.lunch.ingredients.0.quantity' => 80], 'self_week.0.lunch.ingredients.0.quantity'],
    'choose is not an integer' => [['fixed_meals.0.option_groups.0.choose' => 'una'], 'fixed_meals.0.option_groups.0.choose'],
]);

// ---------------------------------------------------------------------------
// 3. Incumple una regla del negocio: el dominio, con su mensaje en español
// ---------------------------------------------------------------------------

it('rejects a plan that breaks a business rule, with the domain message', function (array $changes, string $message) {
    $this->postJson('/api/nutrition-plans', planPayload($changes))
        ->assertUnprocessable()
        ->assertExactJson(['message' => $message]);

    expect(DB::table('nutrition_plans')->count())->toBe(0);
})->with([
    'starts on a Tuesday' => [
        ['starts_on' => '2026-11-03'],
        'Un plan empieza siempre en lunes, y el 03/11/2026 es martes.',
    ],
    'a week with five menus' => [
        ['nutritionist_week.5' => 'FORGET'],
        'Una semana debe tener 6 días con menú (de lunes a viernes y el domingo), y esta tiene 5.',
    ],
    'a menu on the free Saturday' => [
        ['self_week.5.lunch' => ['name' => 'Pizza casera', 'ingredients' => []]],
        'El sábado es día libre: no puede tener menú.',
    ],
    'a menu without dinner' => [
        ['nutritionist_week.0.dinner' => null],
        'Al menú del lunes le falta la cena.',
    ],
    'an unknown meal type' => [
        ['fixed_meals.0.meal_type' => 'brunch'],
        '"brunch" no es un tipo de comida: las pautas fijas son el desayuno (breakfast), la media mañana (mid_morning) y la media tarde (afternoon_snack).',
    ],
    'a repeated menu' => [
        ['nutritionist_week.1.menu' => 1],
        'El menú 1 aparece más de una vez en la semana 1.',
    ],
]);

it('rejects a plan that starts on the same day as another one', function () {
    $this->postJson('/api/nutrition-plans', planPayload())->assertCreated();

    $this->postJson('/api/nutrition-plans', planPayload(['number' => 28]))
        ->assertUnprocessable()
        ->assertExactJson(['message' => 'El plan 28 no puede empezar el 02/11/2026: ese día ya empieza el plan 27.']);

    expect(DB::table('nutrition_plans')->count())->toBe(1);
});
