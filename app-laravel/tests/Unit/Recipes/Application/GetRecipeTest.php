<?php

/*
| Tests del caso de uso GetRecipe.
|
| Como los de CreateRecipe, usan el adaptador en memoria: el núcleo se
| prueba sin Laravel ni PostgreSQL. Comprueban el contrato de la consulta:
| devuelve una VISTA (no la entidad), con las cantidades escritas como en los
| planes, y "no encontrado" es un resultado explícito.
*/

use Recipes\Application\GetRecipe;
use Recipes\Application\IngredientView;
use Recipes\Application\RecipeNotFound;
use Recipes\Application\RecipeView;
use Recipes\Domain\InvalidRecipeId;
use Recipes\Domain\RecipeId;
use Recipes\Infrastructure\InMemoryRecipeRepository;
use Shared\Application\NotFound;
use Shared\Domain\DomainException;
use Tests\Support\Recipes\RecipeMother;

it('returns a read view of the recipe, not the entity', function () {
    $repository = new InMemoryRecipeRepository;
    $recipe = RecipeMother::lentilsWithPisto();
    $repository->save($recipe);

    $view = (new GetRecipe($repository))($recipe->id()->value());

    // El tipo de retorno es la vista: el controlador nunca recibe la Recipe
    // y, por tanto, no puede llamar a rename() ni acoplarse a su estructura.
    expect($view)->toBeInstanceOf(RecipeView::class)
        ->and($view->id)->toBe($recipe->id()->value())
        ->and($view->name)->toBe('Lentejas con pisto')
        ->and($view->servings)->toBe(2)
        ->and($view->steps)->toBe([
            'Calentar el pisto en una sartén',
            'Añadir las lentejas escurridas',
            'Cocinar 5 minutos y salar al gusto',
        ]);
});

it('writes each ingredient as it appears in the plans', function () {
    $repository = new InMemoryRecipeRepository;
    $recipe = RecipeMother::lentilsWithPisto();
    $repository->save($recipe);

    $view = (new GetRecipe($repository))($recipe->id()->value());

    // Exactas y legibles: ½ (no 0.5), 1 ½ (no 3/2), y la sal "al gusto" se
    // distingue explícitamente, sin cantidad ni unidad.
    expect($view->ingredients)->toEqual([
        new IngredientView('Lentejas cocidas', '½', 'jar', 'bote', false),
        new IngredientView('Pisto', '200', 'gram', 'gramo', false),
        new IngredientView('Aceite de oliva', '1 ½', 'tablespoon', 'cucharada sopera', false),
        new IngredientView('Sal', null, null, null, true),
    ]);
});

it('does not change anything: querying is free of side effects', function () {
    // CQS: una consulta se puede repetir sin consecuencias.
    $repository = new InMemoryRecipeRepository;
    $recipe = RecipeMother::lentilsWithPisto();
    $repository->save($recipe);
    $getRecipe = new GetRecipe($repository);

    $first = $getRecipe($recipe->id()->value());
    $second = $getRecipe($recipe->id()->value());

    expect($second)->toEqual($first)
        ->and($repository->count())->toBe(1);
});

it('reports a missing recipe as not found, with a message in Spanish', function () {
    $id = RecipeId::generate();

    expect(fn () => (new GetRecipe(new InMemoryRecipeRepository))($id->value()))
        ->toThrow(RecipeNotFound::class, "No existe ninguna receta con el identificador «{$id->value()}».");
});

it('treats "not found" as a query result, not as a broken business rule', function () {
    // RecipeNotFound es un NotFound (→ 404), no una DomainException (→ 422).
    $exception = RecipeNotFound::withId(RecipeId::generate());

    expect($exception)->toBeInstanceOf(NotFound::class)
        ->not->toBeInstanceOf(DomainException::class);
});

it('rejects a malformed id as a domain error', function () {
    // Decisión de HEXM-0011: "abc" no puede ser una receta (422), que no es
    // lo mismo que una receta que no existe (404).
    expect(fn () => (new GetRecipe(new InMemoryRecipeRepository))('abc'))
        ->toThrow(InvalidRecipeId::class, '"abc" no es un identificador de receta válido.');
});
