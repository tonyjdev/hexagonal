<?php

/*
| Tests de contrato del puerto PlanImportRepository (HEXM-0032).
|
| CONCEPTO: tests de contrato (como NutritionPlanRepositoryContractTest)
| La misma batería contra los dos adaptadores: el de memoria (con el que se
| prueban los casos de uso) y el de Eloquent (PostgreSQL, una fila por
| importación con documentos jsonb, ADR 0025). Los dos tienen que guardar y
| devolver la importación ENTERA, en cada uno de sus tres estados.
*/

use Illuminate\Foundation\Testing\RefreshDatabase;
use MealPlanning\Domain\NutritionPlanId;
use MealPlanning\Domain\PlanImport;
use MealPlanning\Domain\PlanImportId;
use MealPlanning\Domain\PlanImportRepository;
use MealPlanning\Domain\PlanImportStatus;
use MealPlanning\Infrastructure\InMemoryPlanImportRepository;
use MealPlanning\Infrastructure\Persistence\Eloquent\EloquentPlanImportRepository;
use Tests\Support\MealPlanning\PlanImportMother;

uses(RefreshDatabase::class);

dataset('plan import repositories', [
    'in memory' => fn () => new InMemoryPlanImportRepository,
    'eloquent' => fn () => new EloquentPlanImportRepository,
]);

/**
 * Todo lo que tiene una importación, en primitivos, para comparar la
 * guardada con la recuperada campo a campo.
 *
 * @return array<string, mixed>
 */
function importContent(?PlanImport $import): array
{
    if ($import === null) {
        return [];
    }

    $nutritionistPlan = $import->nutritionistPlan();

    return [
        'id' => $import->id()->value(),
        'status' => $import->status(),
        'number' => $nutritionistPlan->number()->value(),
        'fixed_meals' => $nutritionistPlan->fixedMeals(),
        'exchanges' => $nutritionistPlan->exchanges(),
        'exchange_rules' => $nutritionistPlan->exchangeRules(),
        'nutritionist_week' => $nutritionistPlan->nutritionistWeek(),
        'self_week' => $import->selfWeek()?->days(),
        'plan' => $import->plan()?->value(),
    ];
}

it('returns null for an unknown id', function (PlanImportRepository $repository) {
    expect($repository->find(PlanImportId::generate()))->toBeNull();
})->with('plan import repositories');

it('finds a saved import complete, in each of its states', function (PlanImportRepository $repository, Closure $import, PlanImportStatus $status) {
    $saved = $import();

    $repository->save($saved);
    $found = $repository->find($saved->id());

    expect($found?->equals($saved))->toBeTrue()
        ->and($found?->status())->toBe($status)
        ->and(importContent($found))->toBe(importContent($saved));
})->with('plan import repositories')->with([
    'awaiting the self week' => [fn () => PlanImportMother::awaitingSelfWeek(), PlanImportStatus::AwaitingSelfWeek],
    'ready to confirm' => [fn () => PlanImportMother::readyToConfirm(), PlanImportStatus::ReadyToConfirm],
    'confirmed' => [fn () => PlanImportMother::confirmed(), PlanImportStatus::Confirmed],
]);

it('replaces the previous version of the same import when saving it again', function (PlanImportRepository $repository) {
    $id = PlanImportId::generate();
    $plan = NutritionPlanId::generate();

    $repository->save(PlanImportMother::awaitingSelfWeek($id));
    $repository->save(PlanImportMother::confirmed($id, $plan));

    $found = $repository->find($id);
    expect($found?->status())->toBe(PlanImportStatus::Confirmed)
        ->and($found?->plan()?->equals($plan))->toBeTrue();
})->with('plan import repositories');

it('keeps several imports apart, even of the same plan', function (PlanImportRepository $repository) {
    $pending = PlanImportMother::awaitingSelfWeek();
    $ready = PlanImportMother::readyToConfirm();

    $repository->save($pending);
    $repository->save($ready);

    expect($repository->find($pending->id())?->status())->toBe(PlanImportStatus::AwaitingSelfWeek)
        ->and($repository->find($ready->id())?->status())->toBe(PlanImportStatus::ReadyToConfirm);
})->with('plan import repositories');
