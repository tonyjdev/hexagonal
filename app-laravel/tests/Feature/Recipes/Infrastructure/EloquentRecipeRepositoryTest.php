<?php

/*
| Tests propios del adaptador de Eloquent: lo que el contrato del puerto NO
| puede ver, porque depende de cómo se guarda en PostgreSQL (las filas, la
| transacción, las restricciones de las tablas).
|
| CONCEPTO: test de integración
| No se simula nada: se usa la base de datos real de los tests
| (hexagonal_test, ADR 0002). Lo que se prueba es que las piezas encajan:
| mapper + Eloquent + esquema + PostgreSQL.
*/

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Recipes\Domain\Recipe;
use Recipes\Infrastructure\Persistence\Eloquent\EloquentRecipeRepository;
use Tests\Support\Recipes\RecipeMother;

uses(RefreshDatabase::class);

/**
 * Hace que falle el INSERT de los pasos: simula un error a mitad del
 * guardado (cuando la cabecera y los ingredientes ya se han escrito).
 *
 * CONCEPTO: beforeExecuting
 * Laravel permite registrar un callback que se ejecuta antes de cada consulta
 * de una conexión. Aquí se usa para provocar un fallo en un punto concreto,
 * sin tocar el código del repositorio ni el esquema.
 */
function failWhenInsertingSteps(): void
{
    DB::connection()->beforeExecuting(function (string $query): void {
        if (str_starts_with($query, 'insert into "recipe_steps"')) {
            throw new RuntimeException('Fallo simulado al guardar los pasos');
        }
    });
}

/**
 * @return array{recipes: int, ingredients: int, steps: int}
 */
function recipeRowCounts(): array
{
    return [
        'recipes' => DB::table('recipes')->count(),
        'ingredients' => DB::table('recipe_ingredients')->count(),
        'steps' => DB::table('recipe_steps')->count(),
    ];
}

it('stores quantities as exact fractions and keeps the order', function () {
    // ½ bote → 1/2 jar; 1 ½ cucharada → 3/2 tablespoon; al gusto → NULL.
    $recipe = RecipeMother::lentilsWithPisto();

    (new EloquentRecipeRepository)->save($recipe);

    $rows = DB::table('recipe_ingredients')
        ->where('recipe_id', $recipe->id()->value())
        ->orderBy('position')
        ->get(['position', 'name', 'numerator', 'denominator', 'unit'])
        ->map(fn (object $row): array => (array) $row)
        ->all();

    expect($rows)->toBe([
        ['position' => 0, 'name' => 'Lentejas cocidas', 'numerator' => 1, 'denominator' => 2, 'unit' => 'jar'],
        ['position' => 1, 'name' => 'Pisto', 'numerator' => 200, 'denominator' => 1, 'unit' => 'gram'],
        ['position' => 2, 'name' => 'Aceite de oliva', 'numerator' => 3, 'denominator' => 2, 'unit' => 'tablespoon'],
        ['position' => 3, 'name' => 'Sal', 'numerator' => null, 'denominator' => null, 'unit' => null],
    ]);
});

it('does not duplicate rows when the same recipe is saved twice', function () {
    $repository = new EloquentRecipeRepository;
    $recipe = RecipeMother::lentilsWithPisto();

    $repository->save($recipe);
    $recipe->rename('Lentejas con pisto y comino');
    $repository->save($recipe);

    expect(recipeRowCounts())->toBe(['recipes' => 1, 'ingredients' => 4, 'steps' => 3]);
});

it('keeps the previous version intact if saving fails halfway', function () {
    // CONCEPTO: atomicidad (la A de ACID)
    // El fallo llega DESPUÉS de actualizar la cabecera y de reemplazar los
    // ingredientes. Sin transacción, quedaría el nombre nuevo con los
    // ingredientes nuevos y SIN pasos: una mezcla de las dos versiones. Con
    // ella, el ROLLBACK deja la versión anterior completa.
    $repository = new EloquentRecipeRepository;
    $original = RecipeMother::lentilsWithPisto();
    $repository->save($original);

    // La versión nueva tiene pasos, para que el INSERT que falla llegue a
    // ejecutarse.
    $updated = RecipeMother::lentilsWithPisto($original->id());
    $updated->rename('Nombre que no debería guardarse');
    failWhenInsertingSteps();

    expect(fn () => $repository->save($updated))->toThrow(RuntimeException::class, 'Fallo simulado');

    $found = $repository->find($original->id());
    expect($found)->toBeInstanceOf(Recipe::class)
        ->and(RecipeMother::snapshot($found ?? $original))->toBe(RecipeMother::snapshot($original));
});

it('stores nothing of a new recipe if saving fails halfway', function () {
    failWhenInsertingSteps();

    expect(fn () => (new EloquentRecipeRepository)->save(RecipeMother::lentilsWithPisto()))
        ->toThrow(RuntimeException::class);

    expect(recipeRowCounts())->toBe(['recipes' => 0, 'ingredients' => 0, 'steps' => 0]);
});

it('rejects an incomplete quantity at the database level', function () {
    // La segunda red: aunque alguien escriba en la tabla sin pasar por el
    // dominio, el CHECK de PostgreSQL no admite media cantidad (un número
    // sin unidad).
    $recipe = RecipeMother::chickpeaSalad();
    (new EloquentRecipeRepository)->save($recipe);

    expect(fn () => DB::table('recipe_ingredients')->insert([
        'recipe_id' => $recipe->id()->value(),
        'position' => 1,
        'name' => 'Atún',
        'numerator' => 1,
        'denominator' => null,
        'unit' => null,
    ]))->toThrow(QueryException::class, 'recipe_ingredients_quantity_check');
});
