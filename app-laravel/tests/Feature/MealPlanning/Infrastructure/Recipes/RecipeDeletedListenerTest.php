<?php

/*
| Tests de la reacción de MealPlanning al borrado de una receta (HEXM-0027),
| de punta a punta y cruzando los dos contextos:
|
|   DELETE /api/recipes/{id} → DeleteRecipe → EventBus (Laravel)
|     → RecipeDeleted → RecipeDeletedListener → UnlinkDeletedRecipe
|     → NutritionPlanRepository (Eloquent, PostgreSQL)
|
| El plan 27 se da de alta por la API (tests/fixtures/nutrition-plan-27.json)
| con dos recetas: el guiso en la comida del menú 6 (semana 1) y del día 8
| (semana 2), y la ensalada en la cena del menú 1. Se borra el guiso y se
| comprueba la regla 24: esos platos se desvinculan y el resto del plan no
| cambia.
*/

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use MealPlanning\Domain\NutritionPlan;
use MealPlanning\Domain\NutritionPlanId;
use MealPlanning\Domain\NutritionPlanRepository;
use MealPlanning\Domain\RecipeReference;
use MealPlanning\Infrastructure\Recipes\RecipeDeletedListener;
use Recipes\Domain\Recipe;
use Recipes\Domain\RecipeDeleted;
use Recipes\Domain\RecipeRepository;
use Tests\Support\Recipes\RecipeMother;

uses(RefreshDatabase::class);

/**
 * Guarda una receta en el recetario y devuelve su id.
 */
function storedRecipe(Recipe $recipe): string
{
    app(RecipeRepository::class)->save($recipe);

    return $recipe->id()->value();
}

/**
 * Da de alta el plan 27 por la API con el guiso y la ensalada enlazados, y
 * devuelve su id.
 */
function registerPlan27With(string $stew, string $salad): string
{
    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) file_get_contents(base_path('tests/fixtures/nutrition-plan-27.json')), true, flags: JSON_THROW_ON_ERROR);
    $payload['nutritionist_week'][5]['lunch_recipe_id'] = $stew;
    $payload['nutritionist_week'][0]['dinner_recipe_id'] = $salad;
    $payload['self_week'][0]['lunch']['recipe_id'] = $stew;

    return test()->postJson('/api/nutrition-plans', $payload)->assertCreated()->json('id');
}

/**
 * El plan tal como lo devuelve la API (GET /api/nutrition-plans/{id}).
 *
 * @return array<string, mixed>
 */
function planAsJson(string $id): array
{
    return test()->getJson("/api/nutrition-plans/{$id}")->assertOk()->json('data');
}

it('registers the listener of RecipeDeleted', function () {
    expect(Event::hasListeners(RecipeDeleted::class))->toBeTrue()
        ->and(Event::getRawListeners()[RecipeDeleted::class])->toContain(RecipeDeletedListener::class);
});

it('unlinks the dishes of plan 27 when their recipe is deleted through the API', function () {
    $stew = storedRecipe(RecipeMother::lentilsWithPisto());
    $salad = storedRecipe(RecipeMother::chickpeaSalad());
    $planId = registerPlan27With($stew, $salad);
    $before = planAsJson($planId);

    $this->deleteJson("/api/recipes/{$stew}")->assertNoContent();

    $after = planAsJson($planId);

    // Los platos del guiso, desvinculados pero con su texto.
    expect($after['weeks'][0]['days'][5]['lunch']['recipe_id'])->toBeNull()
        ->and($after['weeks'][0]['days'][5]['lunch']['description'])->toBe('Guiso de alubias y pollo')
        ->and($after['weeks'][1]['days'][0]['lunch']['recipe_id'])->toBeNull()
        ->and($after['weeks'][1]['days'][0]['lunch']['name'])->toBe($before['weeks'][1]['days'][0]['lunch']['name'])
        // La ensalada sigue enlazada.
        ->and($after['weeks'][0]['days'][0]['dinner']['recipe_id'])->toBe($salad);

    // Y el resto del plan es exactamente el de antes: solo cambian esos dos
    // enlaces.
    $expected = $before;
    $expected['weeks'][0]['days'][5]['lunch']['recipe_id'] = null;
    $expected['weeks'][1]['days'][0]['lunch']['recipe_id'] = null;

    expect($after)->toBe($expected);
});

it('changes no plan when the deleted recipe is not used by any of them', function () {
    $stew = storedRecipe(RecipeMother::lentilsWithPisto());
    $salad = storedRecipe(RecipeMother::chickpeaSalad());
    $unused = storedRecipe(RecipeMother::chickpeaSalad());
    $planId = registerPlan27With($stew, $salad);
    $before = planAsJson($planId);

    $this->deleteJson("/api/recipes/{$unused}")->assertNoContent();

    expect(planAsJson($planId))->toBe($before);
});

it('keeps the deletion when the reaction of the plans fails, and reports the error', function () {
    // Si MealPlanning falla al reaccionar, la receta ya está borrada: el
    // cliente recibe 204 (lo que ha pasado) y el error va al manejador de
    // excepciones, no al cliente (ADR 0019).
    Exceptions::fake();
    $stew = storedRecipe(RecipeMother::lentilsWithPisto());

    app()->instance(NutritionPlanRepository::class, new class implements NutritionPlanRepository
    {
        public function save(NutritionPlan $plan): void {}

        public function find(NutritionPlanId $id): ?NutritionPlan
        {
            return null;
        }

        public function inForceOn(DateTimeImmutable $date): ?NutritionPlan
        {
            return null;
        }

        public function history(): array
        {
            return [];
        }

        public function usingRecipe(RecipeReference $recipe): array
        {
            throw new RuntimeException('La base de datos de planes no responde.');
        }
    });

    $this->deleteJson("/api/recipes/{$stew}")->assertNoContent();

    $this->getJson("/api/recipes/{$stew}")->assertNotFound();
    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'La base de datos de planes no responde.');
});
