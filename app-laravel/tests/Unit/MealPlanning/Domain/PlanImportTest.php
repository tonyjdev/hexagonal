<?php

/*
| Tests del agregado PlanImport (la importación pendiente de un plan) y de su
| identificador.
|
| Comprueban su ciclo de vida (PlanImportStatus): empieza con el Excel de la
| nutricionista, se le añade (o sustituye) la semana 2, se pueden volver a
| subir los dos mientras está pendiente, no se confirma sin semana 2 y, una
| vez confirmada, no cambia. Dominio puro: sin Laravel ni base de datos.
*/

use MealPlanning\Domain\ImportedSelfWeek;
use MealPlanning\Domain\InvalidPlanImport;
use MealPlanning\Domain\InvalidPlanImportId;
use MealPlanning\Domain\NutritionPlanId;
use MealPlanning\Domain\PlanImport;
use MealPlanning\Domain\PlanImportId;
use MealPlanning\Domain\PlanImportStatus;
use Shared\Domain\DomainException;
use Tests\Support\MealPlanning\PlanImportMother;

// --- PlanImportId -------------------------------------------------------------

it('generates distinct plan import ids and rebuilds them from text', function () {
    $id = PlanImportId::generate();

    expect($id->equals(PlanImportId::generate()))->toBeFalse()
        ->and(PlanImportId::fromString(strtoupper($id->value()))->equals($id))->toBeTrue();
});

it('rejects a malformed plan import id, in Spanish', function () {
    PlanImportId::fromString('no-es-un-uuid');
})->throws(InvalidPlanImportId::class, '"no-es-un-uuid" no es un identificador de importación válido.');

// --- Empezar ------------------------------------------------------------------

it('starts with the nutritionist excel, waiting for the self week', function () {
    $import = PlanImportMother::awaitingSelfWeek();

    expect($import->status())->toBe(PlanImportStatus::AwaitingSelfWeek)
        ->and($import->number()->value())->toBe(99)
        ->and($import->nutritionistPlan()->nutritionistWeek())->toHaveCount(6)
        ->and($import->selfWeek())->toBeNull()
        ->and($import->plan())->toBeNull();
});

// --- La semana 2 --------------------------------------------------------------

it('is ready to confirm once it has the self week', function () {
    $pending = PlanImportMother::awaitingSelfWeek();
    $ready = $pending->attachSelfWeek(PlanImportMother::selfWeek());

    expect($ready->status())->toBe(PlanImportStatus::ReadyToConfirm)
        ->and(array_column($ready->selfWeek()?->days() ?? [], 'day'))->toBe([8, 9, 10, 11, 12, 13, 14])
        // Inmutable: la versión nueva es la misma importación (mismo id), y
        // la de antes no ha cambiado.
        ->and($ready->equals($pending))->toBeTrue()
        ->and($pending->status())->toBe(PlanImportStatus::AwaitingSelfWeek);
});

it('replaces the self week while it is pending', function () {
    $ready = PlanImportMother::readyToConfirm();
    $otherWeek = ImportedSelfWeek::of([
        ['day' => 8, 'lunch' => ['name' => 'Otra comida', 'ingredients' => []], 'dinner' => ['name' => 'Otra cena', 'ingredients' => []]],
    ]);

    $replaced = $ready->attachSelfWeek($otherWeek);

    expect($replaced->selfWeek())->toBe($otherWeek)
        ->and($replaced->status())->toBe(PlanImportStatus::ReadyToConfirm);
});

// --- El Excel de la nutricionista ----------------------------------------------

it('replaces the nutritionist excel while it is pending, keeping the self week', function (PlanImport $import) {
    $corrected = PlanImportMother::nutritionistPlan(number: 100);

    $replaced = $import->replaceNutritionistPlan($corrected);

    expect($replaced->number()->value())->toBe(100)
        ->and($replaced->nutritionistPlan())->toBe($corrected)
        ->and($replaced->selfWeek())->toBe($import->selfWeek())
        ->and($replaced->status())->toBe($import->status());
})->with([
    'awaiting the self week' => fn () => PlanImportMother::awaitingSelfWeek(),
    'ready to confirm' => fn () => PlanImportMother::readyToConfirm(),
]);

// --- Confirmar ----------------------------------------------------------------

it('is confirmed with the id of the plan it became', function () {
    $plan = NutritionPlanId::generate();

    $confirmed = PlanImportMother::readyToConfirm()->confirm($plan);

    expect($confirmed->status())->toBe(PlanImportStatus::Confirmed)
        ->and($confirmed->plan()?->equals($plan))->toBeTrue();
});

it('gives the self week to confirm with only when it can be confirmed', function () {
    $import = PlanImportMother::readyToConfirm();

    expect($import->selfWeekForConfirmation())->toBe($import->selfWeek());
});

it('cannot be confirmed without the self week, in Spanish', function (Closure $attempt) {
    $attempt(PlanImportMother::awaitingSelfWeek());
})->with([
    'asking whether it can' => fn (PlanImport $import) => $import->selfWeekForConfirmation(),
    'confirming it' => fn (PlanImport $import) => $import->confirm(NutritionPlanId::generate()),
])->throws(InvalidPlanImport::class, 'La importación del plan 99 no se puede confirmar: falta el Excel de la semana 2.');

it('does not change nor confirm again once confirmed, in Spanish', function (Closure $attempt) {
    $attempt(PlanImportMother::confirmed());
})->with([
    'replacing the nutritionist excel' => fn (PlanImport $import) => $import->replaceNutritionistPlan(PlanImportMother::nutritionistPlan()),
    'attaching a self week' => fn (PlanImport $import) => $import->attachSelfWeek(PlanImportMother::selfWeek()),
    'asking whether it can be confirmed' => fn (PlanImport $import) => $import->selfWeekForConfirmation(),
    'confirming it again' => fn (PlanImport $import) => $import->confirm(NutritionPlanId::generate()),
])->throws(InvalidPlanImport::class, 'La importación del plan 99 ya está confirmada: no se puede cambiar ni volver a confirmar.');

it('reports every forbidden transition as a domain error (HTTP 422)', function () {
    expect(new ReflectionClass(InvalidPlanImport::class))->isSubclassOf(DomainException::class)->toBeTrue();
});

// --- Reconstruir (lo que usa el repositorio) -----------------------------------

it('rebuilds an import in any state with create()', function () {
    $id = PlanImportId::generate();
    $plan = NutritionPlanId::generate();

    $import = PlanImport::create($id, PlanImportMother::nutritionistPlan(), PlanImportMother::selfWeek(), $plan);

    expect($import->id()->equals($id))->toBeTrue()
        ->and($import->status())->toBe(PlanImportStatus::Confirmed);
});

it('cannot be rebuilt confirmed without the self week', function () {
    PlanImport::create(PlanImportId::generate(), PlanImportMother::nutritionistPlan(), null, NutritionPlanId::generate());
})->throws(InvalidPlanImport::class, 'La importación del plan 99 no puede estar confirmada sin la semana 2.');

it('labels its statuses in Spanish', function () {
    expect(array_map(fn (PlanImportStatus $status): string => $status->label(), PlanImportStatus::cases()))
        ->toBe(['pendiente de la semana 2', 'lista para confirmar', 'confirmada']);
});
