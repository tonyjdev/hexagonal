<?php

/*
| Tests de contrato del puerto RecipeRepository.
|
| CONCEPTO: tests de contrato (contract tests)
| Un puerto es una interfaz, y una interfaz de PHP solo obliga a tener los
| métodos con la firma correcta: no dice nada de CÓMO deben comportarse.
| ¿find() devuelve null o lanza una excepción si no existe? ¿save() de una
| receta ya guardada la duplica o la reemplaza? Ese comportamiento es el
| CONTRATO del puerto, y estos tests lo escriben como especificación
| ejecutable.
|
| La misma batería se ejecuta contra TODOS los adaptadores (el dataset de
| abajo). Si un adaptador nuevo (otra base de datos, una API...) pasa estos
| tests, se puede enchufar al caso de uso con confianza. Y si los dos
| adaptadores pasan, los tests de CreateRecipe, que usan el de memoria,
| valen también para producción: el fake no "miente".
|
| Por qué está en tests/Feature y no en tests/Unit: el adaptador de Eloquent
| necesita Laravel arrancado y PostgreSQL (hexagonal_test, ADR 0002). El de
| memoria no lo necesita, pero ejecutarlo aquí es lo que permite compararlos.
| Estos tests sustituyen a InMemoryRecipeRepositoryTest: lo que comprobaba
| ahora es parte del contrato que cumplen los dos.
*/

use Illuminate\Foundation\Testing\RefreshDatabase;
use Recipes\Domain\Ingredient;
use Recipes\Domain\Quantity;
use Recipes\Domain\Recipe;
use Recipes\Domain\RecipeId;
use Recipes\Domain\RecipeRepository;
use Recipes\Domain\Servings;
use Recipes\Domain\Step;
use Recipes\Domain\Unit;
use Recipes\Infrastructure\InMemoryRecipeRepository;
use Recipes\Infrastructure\Persistence\Eloquent\EloquentRecipeRepository;
use Tests\Support\Recipes\RecipeMother;

/*
| CONCEPTO: RefreshDatabase
| Ejecuta las migraciones una vez y envuelve cada test en una transacción que
| se deshace al terminar: cada test empieza con las tablas vacías y no deja
| rastro. (Las transacciones del repositorio, dentro, se convierten en
| SAVEPOINTs de PostgreSQL.)
*/
uses(RefreshDatabase::class);

/*
| CONCEPTO: dataset de Pest con closures
| Cada test con ->with('recipe repositories') se ejecuta una vez por
| adaptador, y en la salida aparece con su nombre ("with (in memory)",
| "with (eloquent)"). Los closures se evalúan DENTRO del test, ya con Laravel
| arrancado y la base de datos lista; por eso no se crean los objetos aquí.
*/
dataset('recipe repositories', [
    'in memory' => fn () => new InMemoryRecipeRepository,
    'eloquent' => fn () => new EloquentRecipeRepository,
]);

it('returns null for an unknown id', function (RecipeRepository $repository) {
    expect($repository->find(RecipeId::generate()))->toBeNull();
})->with('recipe repositories');

it('finds a saved recipe with exactly the same data', function (RecipeRepository $repository) {
    // La receta de ejemplo tiene fracciones (½ bote, 1 ½ cucharada), una
    // cantidad entera y un ingrediente al gusto: ½ tiene que volver como 1/2
    // (no como 0.5) y "al gusto" como ausencia de cantidad. Los pasos, en
    // el mismo orden.
    $recipe = RecipeMother::lentilsWithPisto();

    $repository->save($recipe);
    $found = $repository->find($recipe->id());

    expect($found)->toBeInstanceOf(Recipe::class)
        ->and($found?->equals($recipe))->toBeTrue()
        ->and(RecipeMother::snapshot($found ?? $recipe))->toBe(RecipeMother::snapshot($recipe))
        ->and($found?->ingredients()[3]->isToTaste())->toBeTrue();
})->with('recipe repositories');

it('saves a recipe without steps', function (RecipeRepository $repository) {
    // Regla 12: los pasos son opcionales.
    $recipe = RecipeMother::chickpeaSalad();

    $repository->save($recipe);

    expect($repository->find($recipe->id())?->steps())->toBe([]);
})->with('recipe repositories');

it('does not see changes until the recipe is saved again', function (RecipeRepository $repository) {
    // Renombrar en memoria no es guardar.
    $recipe = RecipeMother::chickpeaSalad();
    $repository->save($recipe);

    $recipe->rename('Ensalada de garbanzos y atún');
    expect($repository->find($recipe->id())?->name())->toBe('Ensalada de garbanzos');

    $repository->save($recipe);
    expect($repository->find($recipe->id())?->name())->toBe('Ensalada de garbanzos y atún');
})->with('recipe repositories');

it('replaces the whole recipe when it is saved again', function (RecipeRepository $repository) {
    // Misma identidad, estado nuevo: menos ingredientes, otras cantidades y
    // un solo paso. Lo guardado tiene que ser exactamente la versión nueva,
    // sin restos de la anterior (ni ingredientes ni pasos sobrantes).
    $original = RecipeMother::lentilsWithPisto();
    $repository->save($original);

    $updated = Recipe::create($original->id(), 'Lentejas con pisto (versión ligera)', Servings::of(3), [
        Ingredient::of('Lentejas cocidas', Quantity::fraction(1, 3, Unit::Jar)),
        Ingredient::toTaste('Pimienta'),
    ], [Step::fromString('Mezclar todo en frío')]);
    $repository->save($updated);

    expect(RecipeMother::snapshot($repository->find($original->id()) ?? $original))
        ->toBe(RecipeMother::snapshot($updated));
})->with('recipe repositories');

it('does not let a found recipe change what is stored', function (RecipeRepository $repository) {
    // Lo que devuelve find() es una copia: cambiarla sin save() no cambia
    // lo guardado (en PostgreSQL es obvio; el adaptador en memoria lo
    // consigue clonando).
    $recipe = RecipeMother::chickpeaSalad();
    $repository->save($recipe);

    $repository->find($recipe->id())?->rename('Cambio sin guardar');

    expect($repository->find($recipe->id())?->name())->toBe('Ensalada de garbanzos');
})->with('recipe repositories');

it('keeps different recipes apart', function (RecipeRepository $repository) {
    $lentils = RecipeMother::lentilsWithPisto();
    $salad = RecipeMother::chickpeaSalad();

    $repository->save($lentils);
    $repository->save($salad);

    expect($repository->find($lentils->id())?->name())->toBe('Lentejas con pisto')
        ->and($repository->find($salad->id())?->name())->toBe('Ensalada de garbanzos')
        ->and($repository->find($salad->id())?->ingredients())->toHaveCount(1);
})->with('recipe repositories');
