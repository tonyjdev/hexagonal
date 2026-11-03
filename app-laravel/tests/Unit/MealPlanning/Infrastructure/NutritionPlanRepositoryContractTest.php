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
| Hoy solo hay uno, el de memoria, y por eso el test vive en tests/Unit (no
| necesita Laravel). En el nivel 5 se añadirá 'eloquent' al dataset y el
| fichero pasará a tests/Feature, con RefreshDatabase, como el del
| recetario: un adaptador nuevo es una línea más, no tests nuevos.
|
| Planes de ejemplo: el 26 empieza el lunes 2026-09-07 y el 27, el lunes
| 2026-11-02.
*/

use MealPlanning\Domain\DuplicatedPlanStart;
use MealPlanning\Domain\MealType;
use MealPlanning\Domain\NutritionPlan;
use MealPlanning\Domain\NutritionPlanId;
use MealPlanning\Domain\NutritionPlanRepository;
use MealPlanning\Domain\Weekday;
use MealPlanning\Infrastructure\InMemoryNutritionPlanRepository;
use Tests\Support\MealPlanning\NutritionPlanMother;

dataset('nutrition plan repositories', [
    'in memory' => fn () => new InMemoryNutritionPlanRepository,
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

it('keeps the first plan when the duplicate is rejected', function (NutritionPlanRepository $repository) {
    $repository->save(NutritionPlanMother::plan(27, '2026-11-02'));

    try {
        $repository->save(NutritionPlanMother::plan(28, '2026-11-02'));
    } catch (DuplicatedPlanStart) {
        // Esperado: lo que importa es lo que queda guardado.
    }

    expect(planInForceOn($repository, '2026-11-02'))->toBe(27);
})->with('nutrition plan repositories');
