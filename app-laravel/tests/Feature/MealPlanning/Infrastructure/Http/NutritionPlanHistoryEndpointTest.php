<?php

/*
| Tests de GET /api/nutrition-plans (el histórico) y
| GET /api/nutrition-plans/{id} (un plan entero), de punta a punta contra
| PostgreSQL. "Hoy" se fija sustituyendo el puerto Clock en el contenedor
| (HEXM-0022).
|
| Planes 26 (inicio 07/09/2026) y 27 (inicio 02/11/2026).
*/

use Illuminate\Foundation\Testing\RefreshDatabase;
use MealPlanning\Domain\NutritionPlan;
use MealPlanning\Domain\NutritionPlanId;
use MealPlanning\Domain\NutritionPlanRepository;
use Shared\Application\Clock;
use Shared\Infrastructure\FrozenClock;
use Tests\Support\MealPlanning\NutritionPlanMother;

uses(RefreshDatabase::class);

/**
 * Guarda los planes 26 y 27 y devuelve el 26 y el 27, en ese orden.
 *
 * @return array{NutritionPlan, NutritionPlan}
 */
function savePlans26And27(): array
{
    $p26 = NutritionPlanMother::plan(26, '2026-09-07');
    $p27 = NutritionPlanMother::plan(27, '2026-11-02');
    app(NutritionPlanRepository::class)->save($p26);
    app(NutritionPlanRepository::class)->save($p27);

    return [$p26, $p27];
}

it('lists the history from the most recent plan, with its validity', function () {
    // Criterio de aceptación: el 27 vigente y el 26 del 07/09 al 01/11.
    [$p26, $p27] = savePlans26And27();
    $this->app->instance(Clock::class, FrozenClock::on('2026-11-11'));

    $this->getJson('/api/nutrition-plans')
        ->assertOk()
        ->assertExactJson(['data' => [
            [
                'id' => $p27->id()->value(), 'number' => 27, 'starts_on' => '2026-11-02', 'ends_on' => null,
                'status' => 'in_force', 'status_label' => 'vigente',
                'url' => route('nutrition-plans.show', ['id' => $p27->id()->value()]),
            ],
            [
                'id' => $p26->id()->value(), 'number' => 26, 'starts_on' => '2026-09-07', 'ends_on' => '2026-11-01',
                'status' => 'finished', 'status_label' => 'finalizado',
                'url' => route('nutrition-plans.show', ['id' => $p26->id()->value()]),
            ],
        ]]);
});

it('lists an empty history when there are no plans', function () {
    $this->getJson('/api/nutrition-plans')->assertOk()->assertExactJson(['data' => []]);
});

it('returns a whole plan from the history', function () {
    // Criterio de aceptación: pautas fijas, intercambios y las dos semanas.
    [$p26] = savePlans26And27();

    $this->getJson('/api/nutrition-plans/'.$p26->id()->value())
        ->assertOk()
        ->assertJsonPath('data.number', 26)
        ->assertJsonPath('data.starts_on', '2026-09-07')
        ->assertJsonPath('data.fixed_meals.0.meal_type_label', 'desayuno')
        ->assertJsonPath('data.fixed_meals.0.option_groups.0.choose', 1)
        ->assertJsonPath('data.exchanges.0', ['category' => 'carbohydrates', 'category_label' => 'hidratos', 'food' => 'Arroz', 'serving' => '60 g en crudo'])
        ->assertJsonPath('data.exchange_rules', ['La grasa solo en la comida o en la cena, no en ambas.'])
        ->assertJsonCount(2, 'data.weeks')
        ->assertJsonCount(6, 'data.weeks.0.days')
        ->assertJsonPath('data.weeks.0.days.5.weekday', 'domingo')
        ->assertJsonPath('data.weeks.0.days.5.lunch', ['name' => null, 'description' => 'Comida del menú 6', 'ingredients' => [], 'recipe_id' => null])
        ->assertJsonPath('data.weeks.1.days.2.lunch', [
            'name' => 'Comida del día 10', 'description' => null,
            'ingredients' => [['name' => 'Pasta integral', 'quantity' => '80 g']],
            'recipe_id' => null,
        ]);
});

it('reports an unknown plan as not found', function () {
    $id = NutritionPlanId::generate()->value();

    $this->getJson("/api/nutrition-plans/{$id}")
        ->assertNotFound()
        ->assertExactJson(['message' => "No existe ningún plan nutricional con el identificador «{$id}»."]);
});

it('rejects a malformed id as a domain error', function () {
    $this->getJson('/api/nutrition-plans/abc')
        ->assertUnprocessable()
        ->assertExactJson(['message' => '"abc" no es un identificador de plan válido.']);
});

it('rejects registering a second plan with the same number', function () {
    // La regla 22 de punta a punta: el índice único de PostgreSQL, traducido
    // a DuplicatedPlanNumber y a un 422 en español.
    savePlans26And27();
    $payload = json_decode((string) file_get_contents(base_path('tests/fixtures/nutrition-plan-27.json')), true, flags: JSON_THROW_ON_ERROR);
    $payload['starts_on'] = '2027-01-04';

    $this->postJson('/api/nutrition-plans', $payload)
        ->assertUnprocessable()
        ->assertExactJson(['message' => 'Ya existe un plan 27 (empieza el 02/11/2026): no puede haber dos planes con el mismo número.']);
});

it('shows the recipe each dish points to', function () {
    // HEXM-0024: recipe_id en los platos, en la consulta de un plan y en el
    // menú de un día; null en los platos sin receta.
    $plan = NutritionPlanMother::planWithRecipes();
    app(NutritionPlanRepository::class)->save($plan);

    $this->getJson('/api/nutrition-plans/'.$plan->id()->value())
        ->assertOk()
        ->assertJsonPath('data.weeks.0.days.5.lunch.recipe_id', NutritionPlanMother::STEW_RECIPE)
        ->assertJsonPath('data.weeks.0.days.5.dinner.recipe_id', null);

    // El domingo 08/11/2026 es el día del menú 6 (semana 1).
    $this->getJson('/api/menu?date=2026-11-08')
        ->assertOk()
        ->assertJsonPath('data.lunch.recipe_id', NutritionPlanMother::STEW_RECIPE)
        ->assertJsonPath('data.dinner.recipe_id', null);
});
