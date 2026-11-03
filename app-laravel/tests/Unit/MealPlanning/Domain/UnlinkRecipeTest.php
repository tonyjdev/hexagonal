<?php

/*
| Tests de la desvinculación de una receta en el dominio de los planes
| (HEXM-0027, regla 24).
|
| CONCEPTO: cambiar un agregado inmutable devolviendo una versión nueva
| withoutRecipe() baja por el agregado (plan → semana → día → plato) y
| devuelve un plan NUEVO, con el mismo id, en el que solo cambian los platos
| que apuntaban a esa receta. El plan de antes no cambia.
|
| El plan de ejemplo (NutritionPlanMother::planWithRecipes()) tiene el guiso
| (STEW_RECIPE) en la comida del domingo de la semana 1 y del miércoles de la
| semana 2, y la ensalada (SALAD_RECIPE) en la cena del lunes de la semana 1.
*/

use MealPlanning\Domain\Dish;
use MealPlanning\Domain\DishIngredient;
use MealPlanning\Domain\RecipeReference;
use MealPlanning\Domain\Weekday;
use Tests\Support\MealPlanning\NutritionPlanMother;

function stew(): RecipeReference
{
    return RecipeReference::fromString(NutritionPlanMother::STEW_RECIPE);
}

it('unlinks a dish from the deleted recipe and keeps everything else', function () {
    // El plato conserva su nombre y sus ingredientes; solo pierde el enlace.
    $dish = Dish::named('Guiso de alubias y pollo', [DishIngredient::of('Alubias', '½ bote')])->withRecipe(stew());

    $unlinked = $dish->withoutRecipe(stew());

    expect($unlinked->recipe())->toBeNull()
        ->and($unlinked->name())->toBe('Guiso de alubias y pollo')
        ->and($unlinked->ingredients()[0]->quantity())->toBe('½ bote')
        // El plato de antes sigue igual: inmutable.
        ->and($dish->recipe()?->value())->toBe(NutritionPlanMother::STEW_RECIPE);
});

it('leaves a dish that points to another recipe, or to none, as it is', function () {
    $salad = Dish::described('Ensalada de garbanzos')->withRecipe(RecipeReference::fromString(NutritionPlanMother::SALAD_RECIPE));
    $plain = Dish::described('Merluza a la plancha');

    expect($salad->withoutRecipe(stew()))->toBe($salad)
        ->and($plain->withoutRecipe(stew()))->toBe($plain);
});

it('unlinks the recipe from every dish of the plan, in both weeks', function () {
    $plan = NutritionPlanMother::planWithRecipes();

    $updated = $plan->withoutRecipe(stew());

    expect($updated->week(1)->day(Weekday::Sunday)?->lunch()->recipe())->toBeNull()
        ->and($updated->week(2)->day(Weekday::Wednesday)?->lunch()->recipe())->toBeNull()
        // El plato sigue siendo el mismo, sin el enlace.
        ->and($updated->week(1)->day(Weekday::Sunday)?->lunch()->description())->toBe('Comida del menú 6')
        ->and($updated->usesRecipe(stew()))->toBeFalse();
});

it('keeps the identity and the rest of the plan', function () {
    $plan = NutritionPlanMother::planWithRecipes();

    $updated = $plan->withoutRecipe(stew());

    // El mismo plan (misma identidad), en una versión nueva.
    expect($updated->equals($plan))->toBeTrue()
        ->and($updated)->not->toBe($plan)
        ->and($updated->number()->value())->toBe(27)
        ->and($updated->startsOn())->toEqual($plan->startsOn())
        ->and($updated->fixedMeals())->toEqual($plan->fixedMeals())
        ->and($updated->exchangeTable())->toEqual($plan->exchangeTable())
        // La otra receta sigue enlazada.
        ->and($updated->week(1)->day(Weekday::Monday)?->dinner()->recipe()?->value())->toBe(NutritionPlanMother::SALAD_RECIPE);
});

it('does not change the plan it starts from', function () {
    $plan = NutritionPlanMother::planWithRecipes();

    $plan->withoutRecipe(stew());

    expect($plan->usesRecipe(stew()))->toBeTrue();
});

it('says whether the plan uses a recipe', function () {
    expect(NutritionPlanMother::planWithRecipes()->usesRecipe(stew()))->toBeTrue()
        ->and(NutritionPlanMother::plan()->usesRecipe(stew()))->toBeFalse()
        ->and(NutritionPlanMother::planWithRecipes()->usesRecipe(RecipeReference::fromString('0190a6e4-5b1c-7d2e-8f3a-4b5c6d7e8f90')))->toBeFalse();
});
