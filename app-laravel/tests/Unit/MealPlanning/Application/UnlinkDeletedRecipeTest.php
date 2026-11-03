<?php

/*
| Tests del caso de uso UnlinkDeletedRecipe (HEXM-0027).
|
| Sin Laravel ni PostgreSQL, y sin el recetario: el caso de uso recibe el id
| de la receta borrada (texto) y el repositorio de planes en memoria, que
| cumple el mismo contrato que el de Eloquent. Comprueban la regla 24:
|   - los platos que apuntaban a la receta se desvinculan (conservan todo lo
|     demás) y el resto del plan no cambia;
|   - una receta que no usa ningún plan no cambia ningún plan;
|   - repetirlo no cambia nada más (idempotente).
*/

use MealPlanning\Application\UnlinkDeletedRecipe;
use MealPlanning\Domain\InvalidRecipeReference;
use MealPlanning\Domain\RecipeReference;
use MealPlanning\Domain\Weekday;
use MealPlanning\Infrastructure\InMemoryNutritionPlanRepository;
use Tests\Support\MealPlanning\NutritionPlanMother;

it('unlinks the deleted recipe from the dishes of the plans that use it', function () {
    $plans = new InMemoryNutritionPlanRepository;
    $plan = NutritionPlanMother::planWithRecipes();
    $plans->save($plan);

    (new UnlinkDeletedRecipe($plans))(NutritionPlanMother::STEW_RECIPE);

    $updated = $plans->find($plan->id());

    // Los dos platos del guiso, sin el enlace pero con su texto.
    expect($updated?->week(1)->day(Weekday::Sunday)?->lunch()->recipe())->toBeNull()
        ->and($updated?->week(1)->day(Weekday::Sunday)?->lunch()->description())->toBe('Comida del menú 6')
        ->and($updated?->week(2)->day(Weekday::Wednesday)?->lunch()->recipe())->toBeNull()
        ->and($updated?->week(2)->day(Weekday::Wednesday)?->lunch()->name())->toBe('Comida del día 10')
        // La ensalada sigue enlazada, y el resto del plan es el de antes.
        ->and($updated?->week(1)->day(Weekday::Monday)?->dinner()->recipe()?->value())->toBe(NutritionPlanMother::SALAD_RECIPE)
        ->and($updated?->number()->value())->toBe(27)
        ->and($updated?->exchangeTable())->toEqual($plan->exchangeTable());
});

it('updates every plan that uses the recipe and leaves the others alone', function () {
    $plans = new InMemoryNutritionPlanRepository;
    $old = NutritionPlanMother::planWithRecipes(26, '2026-09-07');
    $current = NutritionPlanMother::planWithRecipes(27, '2026-11-02');
    $withoutRecipes = NutritionPlanMother::plan(28, '2027-01-04');
    $plans->save($old);
    $plans->save($current);
    $plans->save($withoutRecipes);

    (new UnlinkDeletedRecipe($plans))(NutritionPlanMother::STEW_RECIPE);

    $stew = RecipeReference::fromString(NutritionPlanMother::STEW_RECIPE);

    expect($plans->usingRecipe($stew))->toBe([])
        ->and($plans->find($withoutRecipes->id()))->toBe($withoutRecipes);
});

it('changes no plan when the deleted recipe was not used', function () {
    $plans = new InMemoryNutritionPlanRepository;
    $plan = NutritionPlanMother::planWithRecipes();
    $plans->save($plan);

    (new UnlinkDeletedRecipe($plans))('0190a6e4-5b1c-7d2e-8f3a-4b5c6d7e8f90');

    // El mismo objeto: ni siquiera se ha vuelto a guardar.
    expect($plans->find($plan->id()))->toBe($plan);
});

it('can run twice without changing anything else (idempotent)', function () {
    $plans = new InMemoryNutritionPlanRepository;
    $plans->save(NutritionPlanMother::planWithRecipes());
    $unlink = new UnlinkDeletedRecipe($plans);

    $unlink(NutritionPlanMother::STEW_RECIPE);
    $afterFirst = $plans->history();
    $unlink(NutritionPlanMother::STEW_RECIPE);

    expect($plans->history())->toBe($afterFirst);
});

it('rejects an id that is not a recipe reference', function () {
    expect(fn () => (new UnlinkDeletedRecipe(new InMemoryNutritionPlanRepository))('guiso'))
        ->toThrow(InvalidRecipeReference::class);
});
