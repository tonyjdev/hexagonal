<?php

/*
| Tests de contrato del puerto RecipeCatalog (HEXM-0025).
|
| CONCEPTO: tests de contrato (como NutritionPlanRepositoryContractTest)
| La misma batería contra los dos adaptadores del puerto:
|   - InMemoryRecipeCatalog: el fake con el que se prueba RegisterNutritionPlan;
|   - RecipesContextRecipeCatalog: la capa anticorrupción real, que pregunta
|     a la capa de aplicación de Recipes (GetRecipe) con las recetas
|     guardadas en PostgreSQL.
| Si los dos pasan, lo que demuestran los tests rápidos del caso de uso vale
| también con el recetario de verdad.
|
| Cada adaptador del dataset es una FÁBRICA: recibe las recetas que deben
| existir y devuelve el puerto listo. El fake las recibe en el constructor;
| el real las guarda en el recetario a través de SU puerto (RecipeRepository),
| como lo haría el alta de una receta.
|
| El id mal formado no aparece aquí: un RecipeReference nunca lo es (lo
| rechaza al construirse). Se prueba de punta a punta en
| RegisterNutritionPlanEndpointTest, con este adaptador real enchufado.
*/

use Illuminate\Foundation\Testing\RefreshDatabase;
use MealPlanning\Application\RecipeCatalog;
use MealPlanning\Domain\RecipeReference;
use MealPlanning\Infrastructure\InMemoryRecipeCatalog;
use MealPlanning\Infrastructure\Recipes\RecipesContextRecipeCatalog;
use Recipes\Domain\Recipe;
use Recipes\Domain\RecipeRepository;
use Tests\Support\Recipes\RecipeMother;

uses(RefreshDatabase::class);

dataset('recipe catalogs', [
    // Como el parámetro del test está tipado `Closure`, Pest no la ejecuta:
    // el test recibe la fábrica y la llama con sus recetas.
    'in memory' => fn (Recipe ...$recipes): RecipeCatalog => new InMemoryRecipeCatalog(
        ...array_map(fn (Recipe $recipe) => RecipeReference::fromString($recipe->id()->value()), $recipes),
    ),
    'recipes context' => function (Recipe ...$recipes): RecipeCatalog {
        foreach ($recipes as $recipe) {
            app(RecipeRepository::class)->save($recipe);
        }

        // Del contenedor, como en la aplicación: comprueba también el binding.
        return app(RecipeCatalog::class);
    },
]);

it('says that an existing recipe exists', function (Closure $catalog) {
    $lentils = RecipeMother::lentilsWithPisto();

    expect($catalog($lentils, RecipeMother::chickpeaSalad())->exists(RecipeReference::fromString($lentils->id()->value())))
        ->toBeTrue();
})->with('recipe catalogs');

it('says that a recipe missing from the catalog does not exist', function (Closure $catalog) {
    // Otra receta que nunca se guardó: un UUID válido sin receta detrás.
    $missing = RecipeMother::chickpeaSalad();

    expect($catalog(RecipeMother::lentilsWithPisto())->exists(RecipeReference::fromString($missing->id()->value())))
        ->toBeFalse();
})->with('recipe catalogs');

it('says that nothing exists in an empty catalog', function (Closure $catalog) {
    expect($catalog()->exists(RecipeReference::fromString('0190a6e4-5b1c-7d2e-8f3a-4b5c6d7e8f90')))->toBeFalse();
})->with('recipe catalogs');

it('finds a recipe whatever the case of its id', function (Closure $catalog) {
    // La traducción RecipeReference → id de Recipes no pierde la normalización
    // a minúsculas de los dos lados (Shared\Domain\Uuid).
    $lentils = RecipeMother::lentilsWithPisto();

    expect($catalog($lentils)->exists(RecipeReference::fromString(strtoupper($lentils->id()->value()))))->toBeTrue();
})->with('recipe catalogs');

it('binds the recipe catalog port to the anti-corruption adapter', function () {
    expect(app(RecipeCatalog::class))->toBeInstanceOf(RecipesContextRecipeCatalog::class);
});
