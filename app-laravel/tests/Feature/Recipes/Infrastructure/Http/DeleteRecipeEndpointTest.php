<?php

/*
| Tests de DELETE /api/recipes/{id} (HEXM-0026), de punta a punta:
| ruta → DeleteRecipeController → DeleteRecipe → RecipeRepository (Eloquent,
| PostgreSQL) y EventBus (LaravelEventBus → dispatcher de Laravel).
|
| Los tres resultados, como en GET /api/recipes/{id}:
|   1. la receta existe          → 204, borrada entera y RecipeDeleted publicado;
|   2. no existe                 → 404 y ningún evento;
|   3. el id no puede existir    → 422 y ningún evento.
|
| El evento se observa como lo hará MealPlanning en HEXM-0027: con un oyente
| normal de Laravel (Event::listen). Hoy es el 03/11/2026: se fija el Clock
| en el contenedor, como en los tests de GET /api/menu.
*/

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Recipes\Domain\RecipeDeleted;
use Recipes\Domain\RecipeId;
use Recipes\Domain\RecipeRepository;
use Shared\Application\Clock;
use Shared\Infrastructure\FrozenClock;
use Tests\Support\Recipes\RecipeMother;

uses(RefreshDatabase::class);

beforeEach(function () {
    // $this->received: los RecipeDeleted que llegan a un oyente de Laravel
    // durante el test, como los recibirá MealPlanning en HEXM-0027.
    app()->instance(Clock::class, FrozenClock::on('2026-11-03'));

    $this->received = [];
    Event::listen(RecipeDeleted::class, function (RecipeDeleted $event): void {
        $this->received[] = $event;
    });
});

it('deletes the recipe with its ingredients and steps, and answers 204', function () {
    $lentils = RecipeMother::lentilsWithPisto();
    app(RecipeRepository::class)->save($lentils);
    $id = $lentils->id()->value();

    $this->deleteJson("/api/recipes/{$id}")->assertNoContent();

    // Ya no existe: ni por la API ni en ninguna de sus tablas.
    $this->getJson("/api/recipes/{$id}")->assertNotFound();
    expect(DB::table('recipes')->where('id', $id)->count())->toBe(0)
        ->and(DB::table('recipe_ingredients')->where('recipe_id', $id)->count())->toBe(0)
        ->and(DB::table('recipe_steps')->where('recipe_id', $id)->count())->toBe(0);
});

it('publishes exactly one RecipeDeleted with the id and the day', function () {
    $lentils = RecipeMother::lentilsWithPisto();
    app(RecipeRepository::class)->save($lentils);

    $this->deleteJson("/api/recipes/{$lentils->id()->value()}")->assertNoContent();

    expect($this->received)->toHaveCount(1)
        ->and($this->received[0]->recipeId())->toBe($lentils->id()->value())
        ->and($this->received[0]->occurredOn()->format('Y-m-d'))->toBe('2026-11-03');
});

it('answers 404 and publishes nothing when the recipe does not exist', function () {
    $missing = RecipeId::generate()->value();

    $this->deleteJson("/api/recipes/{$missing}")
        ->assertNotFound()
        ->assertExactJson(['message' => "No existe ninguna receta con el identificador «{$missing}»."]);

    expect($this->received)->toBe([]);
});

it('answers 422 and publishes nothing when the id is malformed', function () {
    $this->deleteJson('/api/recipes/guiso-de-alubias')->assertUnprocessable();

    expect($this->received)->toBe([]);
});

it('cannot delete the same recipe twice', function () {
    $salad = RecipeMother::chickpeaSalad();
    app(RecipeRepository::class)->save($salad);

    $this->deleteJson("/api/recipes/{$salad->id()->value()}")->assertNoContent();
    $this->deleteJson("/api/recipes/{$salad->id()->value()}")->assertNotFound();

    expect($this->received)->toHaveCount(1);
});
