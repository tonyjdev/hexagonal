<?php

/*
| Tests del adaptador en memoria. Comprueban que se comporta como una base de
| datos real: lo guardado no cambia hasta que se vuelve a guardar.
*/

use Recipes\Domain\Ingredient;
use Recipes\Domain\Recipe;
use Recipes\Domain\RecipeId;
use Recipes\Domain\Servings;
use Recipes\Infrastructure\InMemoryRecipeRepository;

function sampleRecipe(string $name = 'Ensalada de garbanzos'): Recipe
{
    return Recipe::create(RecipeId::generate(), $name, Servings::of(2), [Ingredient::toTaste('Garbanzos')]);
}

it('finds a saved recipe by its id', function () {
    $repository = new InMemoryRecipeRepository;
    $recipe = sampleRecipe();

    $repository->save($recipe);

    expect($repository->find($recipe->id())?->equals($recipe))->toBeTrue();
});

it('returns null for an unknown id', function () {
    expect((new InMemoryRecipeRepository)->find(RecipeId::generate()))->toBeNull();
});

it('does not see changes until the recipe is saved again', function () {
    // Como en una base de datos: renombrar en memoria no es guardar.
    $repository = new InMemoryRecipeRepository;
    $recipe = sampleRecipe();
    $repository->save($recipe);

    $recipe->rename('Ensalada de garbanzos y atún');
    expect($repository->find($recipe->id())?->name())->toBe('Ensalada de garbanzos');

    $repository->save($recipe);
    expect($repository->find($recipe->id())?->name())->toBe('Ensalada de garbanzos y atún')
        // Guardar otra vez la misma receta la reemplaza, no la duplica.
        ->and($repository->count())->toBe(1);
});

it('does not let a found recipe change what is stored', function () {
    $repository = new InMemoryRecipeRepository;
    $recipe = sampleRecipe();
    $repository->save($recipe);

    $repository->find($recipe->id())?->rename('Cambio sin guardar');

    expect($repository->find($recipe->id())?->name())->toBe('Ensalada de garbanzos');
});
