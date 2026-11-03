<?php

/*
| Tests de contrato del puerto NutritionPlanRepository.
|
| CONCEPTO: tests de contrato (ver RecipeRepositoryContractTest)
| La interfaz solo obliga a tener los métodos; el COMPORTAMIENTO (qué plan
| está vigente, qué pasa con dos planes que empiezan el mismo día) es el
| contrato, y estos tests lo escriben como especificación ejecutable. La
| misma batería se ejecuta contra todos los adaptadores del dataset.
|
| Desde HEXM-0020 hay dos adaptadores: el de memoria y el de Eloquent
| (PostgreSQL, una fila por plan con documentos jsonb, ADR 0013). Por eso el
| test vive en tests/Feature, con RefreshDatabase, como el del recetario: el
| adaptador nuevo fue una línea más en el dataset, no tests nuevos.
|
| Planes de ejemplo: el 26 empieza el lunes 2026-09-07 y el 27, el lunes
| 2026-11-02.
*/

use Illuminate\Foundation\Testing\RefreshDatabase;
use MealPlanning\Domain\DuplicatedPlanNumber;
use MealPlanning\Domain\DuplicatedPlanStart;
use MealPlanning\Domain\MealType;
use MealPlanning\Domain\NutritionPlan;
use MealPlanning\Domain\NutritionPlanId;
use MealPlanning\Domain\NutritionPlanRepository;
use MealPlanning\Domain\RecipeReference;
use MealPlanning\Domain\Weekday;
use MealPlanning\Infrastructure\InMemoryNutritionPlanRepository;
use MealPlanning\Infrastructure\Persistence\Eloquent\EloquentNutritionPlanRepository;
use Tests\Support\MealPlanning\NutritionPlanMother;

// Cada test empieza con las tablas vacías (ver RecipeRepositoryContractTest).
uses(RefreshDatabase::class);

dataset('nutrition plan repositories', [
    'in memory' => fn () => new InMemoryNutritionPlanRepository,
    'eloquent' => fn () => new EloquentNutritionPlanRepository,
]);

/**
 * El número del plan vigente en una fecha, o null. Resume la respuesta del
 * repositorio en algo fácil de comparar.
 */
function planInForceOn(NutritionPlanRepository $repository, string $date): ?int
{
    return $repository->inForceOn(new DateTimeImmutable($date))?->number()->value();
}

// --- save() y find() ----------------------------------------------------------

it('returns null for an unknown id', function (NutritionPlanRepository $repository) {
    expect($repository->find(NutritionPlanId::generate()))->toBeNull();
})->with('nutrition plan repositories');

it('finds a saved plan complete: weeks, fixed meals and exchanges', function (NutritionPlanRepository $repository) {
    $plan = NutritionPlanMother::plan();

    $repository->save($plan);
    $found = $repository->find($plan->id());

    // equals() compara la identidad; el resto de aserciones comprueban que
    // el plan vuelve ENTERO (lo importante cuando llegue PostgreSQL).
    expect($found)->toBeInstanceOf(NutritionPlan::class)
        ->and($found?->equals($plan))->toBeTrue()
        ->and($found?->number()->value())->toBe(27)
        ->and($found?->startsOn()->format('Y-m-d'))->toBe('2026-11-02')
        ->and($found?->fixedMeal(MealType::Breakfast)?->equals($plan->fixedMeal(MealType::Breakfast) ?? throw new LogicException))->toBeTrue()
        ->and($found?->exchangeTable()->equals($plan->exchangeTable()))->toBeTrue()
        ->and($found?->week(1)->day(Weekday::Sunday)?->lunch()->description())->toBe('Comida del menú 6')
        ->and($found?->week(2)->day(Weekday::Wednesday)?->lunch()->name())->toBe('Comida del día 10');
})->with('nutrition plan repositories');

it('returns the plan with exactly the same content, part by part', function (NutritionPlanRepository $repository) {
    // equals() de un agregado compara solo la IDENTIDAD (el id). Para saber
    // que el plan vuelve entero, toEqual() compara objeto a objeto todas las
    // propiedades: pautas fijas con sus grupos "elige N", intercambios con
    // sus reglas en orden, las dos semanas con sus seis días y cada plato en
    // su formato (texto libre o nombre con ingredientes). Si el mapper
    // perdiera un solo dato, este test fallaría con el adaptador de Eloquent.
    $plan = NutritionPlanMother::plan();

    $repository->save($plan);

    expect($repository->find($plan->id()))->toEqual($plan);
})->with('nutrition plan repositories');

it('keeps the recipe each dish points to', function (NutritionPlanRepository $repository) {
    // HEXM-0024: la referencia a una receta viaja con el plato, en los dos
    // formatos (semana 1 y semana 2); los platos sin receta siguen sin ella.
    $plan = NutritionPlanMother::planWithRecipes();

    $repository->save($plan);
    $found = $repository->find($plan->id());

    expect($found)->toEqual($plan)
        ->and($found?->week(1)->day(Weekday::Sunday)?->lunch()->recipe()?->value())->toBe(NutritionPlanMother::STEW_RECIPE)
        ->and($found?->week(2)->day(Weekday::Wednesday)?->lunch()->recipe()?->value())->toBe(NutritionPlanMother::STEW_RECIPE)
        ->and($found?->week(2)->day(Weekday::Wednesday)?->dinner()->recipe())->toBeNull();
})->with('nutrition plan repositories');

it('replaces a plan when it is saved again with the same id', function (NutritionPlanRepository $repository) {
    // Mismo id: es el mismo plan (una corrección del número, por ejemplo).
    // No cuenta como "otro plan que empieza el mismo día".
    $id = NutritionPlanId::generate();
    $repository->save(NutritionPlanMother::plan(27, id: $id));

    $repository->save(NutritionPlanMother::plan(28, id: $id));

    expect($repository->find($id)?->number()->value())->toBe(28)
        ->and(planInForceOn($repository, '2026-11-02'))->toBe(28);
})->with('nutrition plan repositories');

// --- inForceOn(): el plan vigente ---------------------------------------------

it('has no plan in force when nothing is saved', function (NutritionPlanRepository $repository) {
    expect(planInForceOn($repository, '2026-11-02'))->toBeNull();
})->with('nutrition plan repositories');

it('finds the plan in force on each date', function (NutritionPlanRepository $repository) {
    $repository->save(NutritionPlanMother::plan(26, '2026-09-07'));
    $repository->save(NutritionPlanMother::plan(27, '2026-11-02'));

    expect(planInForceOn($repository, '2026-08-01'))->toBeNull()   // antes del primero
        ->and(planInForceOn($repository, '2026-09-07'))->toBe(26)  // el día que empieza
        ->and(planInForceOn($repository, '2026-10-15'))->toBe(26)
        ->and(planInForceOn($repository, '2026-11-01'))->toBe(26)  // el último día del 26
        ->and(planInForceOn($repository, '2026-11-02'))->toBe(27)  // el 27 cierra el 26
        ->and(planInForceOn($repository, '2026-12-20'))->toBe(27); // sin fecha de fin
})->with('nutrition plan repositories');

it('does not depend on the order in which plans are saved', function (NutritionPlanRepository $repository) {
    $repository->save(NutritionPlanMother::plan(27, '2026-11-02'));
    $repository->save(NutritionPlanMother::plan(26, '2026-09-07'));

    expect(planInForceOn($repository, '2026-10-15'))->toBe(26)
        ->and(planInForceOn($repository, '2026-12-20'))->toBe(27);
})->with('nutrition plan repositories');

it('compares calendar dates, not times', function (NutritionPlanRepository $repository) {
    $repository->save(NutritionPlanMother::plan(26, '2026-09-07'));
    $repository->save(NutritionPlanMother::plan(27, '2026-11-02'));

    // El último minuto del 1 de noviembre sigue siendo del plan 26, y el
    // primero del día 2 ya es del 27. Las 00:30 del día 2 en Madrid (23:30
    // del 1 en UTC) también: cuenta la fecha de quien pregunta.
    $madrid = new DateTimeImmutable('2026-11-02 00:30', new DateTimeZone('Europe/Madrid'));

    expect(planInForceOn($repository, '2026-11-01 23:59'))->toBe(26)
        ->and(planInForceOn($repository, '2026-11-02 00:00'))->toBe(27)
        ->and($repository->inForceOn($madrid)?->number()->value())->toBe(27);
})->with('nutrition plan repositories');

// --- Dos planes no pueden empezar el mismo día --------------------------------

it('rejects another plan that starts on the same day', function (NutritionPlanRepository $repository) {
    $repository->save(NutritionPlanMother::plan(27, '2026-11-02'));

    $repository->save(NutritionPlanMother::plan(28, '2026-11-02'));
})->with('nutrition plan repositories')
    ->throws(DuplicatedPlanStart::class, 'El plan 28 no puede empezar el 02/11/2026: ese día ya empieza el plan 27.');

it('rejects moving a saved plan to a day on which another plan starts', function (NutritionPlanRepository $repository) {
    // El otro camino del duplicado: no es un plan nuevo (INSERT), sino uno ya
    // guardado al que se le cambia la fecha (UPDATE). Corregir la fecha de
    // un plan es legítimo, pero no a un día que ya ocupa otro.
    $repository->save(NutritionPlanMother::plan(26, '2026-09-07'));
    $id = NutritionPlanId::generate();
    $repository->save(NutritionPlanMother::plan(27, '2026-11-02', $id));

    expect(fn () => $repository->save(NutritionPlanMother::plan(27, '2026-09-07', $id)))
        ->toThrow(DuplicatedPlanStart::class, 'El plan 27 no puede empezar el 07/09/2026: ese día ya empieza el plan 26.');

    // Los dos planes siguen como estaban.
    expect(planInForceOn($repository, '2026-10-15'))->toBe(26)
        ->and(planInForceOn($repository, '2026-11-02'))->toBe(27);
})->with('nutrition plan repositories');

it('keeps the first plan when the duplicate is rejected', function (NutritionPlanRepository $repository) {
    $repository->save(NutritionPlanMother::plan(27, '2026-11-02'));

    try {
        $repository->save(NutritionPlanMother::plan(28, '2026-11-02'));
    } catch (DuplicatedPlanStart) {
        // Esperado: lo que importa es lo que queda guardado.
    }

    expect(planInForceOn($repository, '2026-11-02'))->toBe(27);
})->with('nutrition plan repositories');

// --- Dos planes no pueden tener el mismo número (regla 22, HEXM-0023) --------

it('rejects another plan with the same number', function (NutritionPlanRepository $repository) {
    $repository->save(NutritionPlanMother::plan(27, '2026-11-02'));

    $repository->save(NutritionPlanMother::plan(27, '2027-01-04'));
})->with('nutrition plan repositories')
    ->throws(DuplicatedPlanNumber::class, 'Ya existe un plan 27 (empieza el 02/11/2026): no puede haber dos planes con el mismo número.');

it('rejects renumbering a saved plan with the number of another one', function (NutritionPlanRepository $repository) {
    // El camino del UPDATE: corregir el número de un plan es legítimo, pero
    // no al de otro plan.
    $repository->save(NutritionPlanMother::plan(26, '2026-09-07'));
    $id = NutritionPlanId::generate();
    $repository->save(NutritionPlanMother::plan(27, '2026-11-02', $id));

    expect(fn () => $repository->save(NutritionPlanMother::plan(26, '2026-11-02', $id)))
        ->toThrow(DuplicatedPlanNumber::class, 'Ya existe un plan 26 (empieza el 07/09/2026)');

    expect($repository->find($id)?->number()->value())->toBe(27);
})->with('nutrition plan repositories');

it('reports the same start first when a plan clashes on both', function (NutritionPlanRepository $repository) {
    // Mismo día y mismo número: el contrato fija qué error gana, para que
    // los dos adaptadores respondan igual.
    $repository->save(NutritionPlanMother::plan(27, '2026-11-02'));

    $repository->save(NutritionPlanMother::plan(27, '2026-11-02'));
})->with('nutrition plan repositories')
    ->throws(DuplicatedPlanStart::class, 'El plan 27 no puede empezar el 02/11/2026: ese día ya empieza el plan 27.');

// --- history(): el histórico de planes (HEXM-0023) -----------------------------

it('has an empty history when nothing is saved', function (NutritionPlanRepository $repository) {
    expect($repository->history())->toBe([]);
})->with('nutrition plan repositories');

it('lists the history from the most recent plan to the oldest', function (NutritionPlanRepository $repository) {
    // Guardados en desorden: el orden lo da la fecha de inicio, no el alta.
    $repository->save(NutritionPlanMother::plan(26, '2026-09-07'));
    $repository->save(NutritionPlanMother::plan(28, '2027-01-04'));
    $repository->save(NutritionPlanMother::plan(25, '2026-07-06'));
    $repository->save(NutritionPlanMother::plan(27, '2026-11-02'));

    $numbers = array_map(fn (NutritionPlan $plan): int => $plan->number()->value(), $repository->history());

    expect($numbers)->toBe([28, 27, 26, 25]);
})->with('nutrition plan repositories');

it('returns complete plans in the history', function (NutritionPlanRepository $repository) {
    $plan = NutritionPlanMother::plan();
    $repository->save($plan);

    expect($repository->history())->toEqual([$plan]);
})->with('nutrition plan repositories');

// --- usingRecipe(): los planes que usan una receta (HEXM-0027) ----------------

it('finds the plans whose dishes point to a recipe', function (NutritionPlanRepository $repository) {
    // El 26 sin recetas; el 27 con el guiso (dos platos) y la ensalada.
    $withRecipes = NutritionPlanMother::planWithRecipes(27, '2026-11-02');
    $repository->save(NutritionPlanMother::plan(26, '2026-09-07'));
    $repository->save($withRecipes);

    $found = $repository->usingRecipe(RecipeReference::fromString(NutritionPlanMother::STEW_RECIPE));

    expect($found)->toHaveCount(1)
        ->and($found[0]->equals($withRecipes))->toBeTrue()
        // Planes completos, como find().
        ->and($found[0])->toEqual($withRecipes);
})->with('nutrition plan repositories');

it('finds a recipe used in a single dish of the week 1 dinners', function (NutritionPlanRepository $repository) {
    // La ensalada solo está en una cena: la búsqueda mira comidas y cenas.
    $repository->save(NutritionPlanMother::planWithRecipes());

    expect($repository->usingRecipe(RecipeReference::fromString(NutritionPlanMother::SALAD_RECIPE)))->toHaveCount(1);
})->with('nutrition plan repositories');

it('finds no plan for a recipe nobody uses', function (NutritionPlanRepository $repository) {
    $repository->save(NutritionPlanMother::planWithRecipes());

    expect($repository->usingRecipe(RecipeReference::fromString('0190a6e4-5b1c-7d2e-8f3a-4b5c6d7e8f90')))->toBe([]);
})->with('nutrition plan repositories');

it('lists the plans that use a recipe from the most recent to the oldest', function (NutritionPlanRepository $repository) {
    $repository->save(NutritionPlanMother::planWithRecipes(26, '2026-09-07'));
    $repository->save(NutritionPlanMother::planWithRecipes(28, '2027-01-04'));
    $repository->save(NutritionPlanMother::planWithRecipes(27, '2026-11-02'));

    $numbers = array_map(
        fn (NutritionPlan $plan): int => $plan->number()->value(),
        $repository->usingRecipe(RecipeReference::fromString(NutritionPlanMother::STEW_RECIPE)),
    );

    expect($numbers)->toBe([28, 27, 26]);
})->with('nutrition plan repositories');

it('stops finding a plan once its recipe is unlinked and saved', function (NutritionPlanRepository $repository) {
    // La versión nueva del plan (mismo id) reemplaza a la anterior.
    $stew = RecipeReference::fromString(NutritionPlanMother::STEW_RECIPE);
    $plan = NutritionPlanMother::planWithRecipes();
    $repository->save($plan);

    $repository->save($plan->withoutRecipe($stew));

    expect($repository->usingRecipe($stew))->toBe([])
        ->and($repository->usingRecipe(RecipeReference::fromString(NutritionPlanMother::SALAD_RECIPE)))->toHaveCount(1);
})->with('nutrition plan repositories');
