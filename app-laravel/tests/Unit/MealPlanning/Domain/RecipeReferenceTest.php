<?php

/*
| Tests de la referencia de un plato a una receta del recetario
| (RecipeReference y Dish::withRecipe(), HEXM-0024).
*/

use MealPlanning\Domain\Dish;
use MealPlanning\Domain\DishIngredient;
use MealPlanning\Domain\InvalidRecipeReference;
use MealPlanning\Domain\RecipeReference;
use Shared\Domain\DomainException;

const STEW_RECIPE_ID = '0190a2a0-7c1e-7000-8000-00000000abcd';

it('references a recipe by its UUID, normalised to lower case', function () {
    $reference = RecipeReference::fromString('0190A2A0-7C1E-7000-8000-00000000ABCD');

    expect($reference->value())->toBe(STEW_RECIPE_ID)
        ->and($reference->equals(RecipeReference::fromString(STEW_RECIPE_ID)))->toBeTrue();
});

it('rejects a malformed reference as a domain error, in Spanish', function () {
    expect(fn () => RecipeReference::fromString('guiso'))
        ->toThrow(InvalidRecipeReference::class, '"guiso" no es una referencia de receta válida.');

    expect(InvalidRecipeReference::malformed('x'))->toBeInstanceOf(DomainException::class);
});

it('links a dish of either format to a recipe, without changing the original', function () {
    // Inmutable: withRecipe() devuelve un plato nuevo.
    $described = Dish::described('Guiso de alubias y pollo');
    $named = Dish::named('Pasta con salmón', [DishIngredient::of('Pasta', '80 g')]);

    $linkedDescribed = $described->withRecipe(RecipeReference::fromString(STEW_RECIPE_ID));
    $linkedNamed = $named->withRecipe(RecipeReference::fromString(STEW_RECIPE_ID));

    expect($described->recipe())->toBeNull()
        ->and($linkedDescribed->recipe()?->value())->toBe(STEW_RECIPE_ID)
        ->and($linkedDescribed->description())->toBe('Guiso de alubias y pollo')
        ->and($linkedNamed->recipe()?->value())->toBe(STEW_RECIPE_ID)
        ->and($linkedNamed->ingredients())->toHaveCount(1);
});

it('considers the recipe when comparing dishes', function () {
    $dish = Dish::described('Guiso de alubias y pollo');
    $linked = $dish->withRecipe(RecipeReference::fromString(STEW_RECIPE_ID));
    $other = $dish->withRecipe(RecipeReference::fromString('0190a2a0-7c1e-7000-8000-00000000ffff'));

    expect($linked->equals($dish))->toBeFalse()
        ->and($dish->equals($linked))->toBeFalse()
        ->and($linked->equals($other))->toBeFalse()
        ->and($linked->equals(Dish::described('Guiso de alubias y pollo')->withRecipe(RecipeReference::fromString(STEW_RECIPE_ID))))->toBeTrue()
        ->and($dish->equals(Dish::described('Guiso de alubias y pollo')))->toBeTrue();
});
