<?php

/*
| Tests propios del adaptador EloquentPlanImportRepository: lo que no es
| contrato del puerto, sino de cómo se guarda (ADR 0025).
|
| - La fila: estado como columna, documentos jsonb y NULL donde falta algo.
| - El CHECK de la tabla: rechaza filas incoherentes escritas a mano.
| - El mapper: un documento corrupto se rechaza al leerlo, con su ruta.
*/

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use MealPlanning\Domain\PlanImportId;
use MealPlanning\Infrastructure\Persistence\Eloquent\EloquentPlanImportRepository;
use Tests\Support\MealPlanning\PlanImportMother;

uses(RefreshDatabase::class);

it('stores one row per import, with its status and its documents', function () {
    $import = PlanImportMother::readyToConfirm();

    (new EloquentPlanImportRepository)->save($import);

    $row = DB::table('plan_imports')->where('id', $import->id()->value())->first();
    expect($row?->status)->toBe('ready_to_confirm')
        ->and($row?->nutrition_plan_id)->toBeNull()
        ->and(json_decode(is_string($row?->nutritionist_plan) ? $row->nutritionist_plan : '{}', true))->toHaveKey('number', 99)
        ->and(json_decode(is_string($row?->self_week) ? $row->self_week : '[]', true))->toHaveCount(7);
});

it('leaves the self week as NULL while it has not arrived', function () {
    $import = PlanImportMother::awaitingSelfWeek();

    (new EloquentPlanImportRepository)->save($import);

    expect(DB::table('plan_imports')->where('id', $import->id()->value())->value('self_week'))->toBeNull();
});

it('refuses, in the database, a confirmed import without a plan', function () {
    $import = PlanImportMother::readyToConfirm();
    (new EloquentPlanImportRepository)->save($import);

    // Un UPDATE a mano que deja la fila incoherente: el CHECK lo impide.
    DB::table('plan_imports')->where('id', $import->id()->value())->update(['status' => 'confirmed']);
})->throws(QueryException::class, 'plan_imports_status_check');

it('rejects a corrupted document when reading it, saying where', function () {
    $import = PlanImportMother::readyToConfirm();
    $repository = new EloquentPlanImportRepository;
    $repository->save($import);

    DB::table('plan_imports')->where('id', $import->id()->value())->update([
        'self_week' => DB::raw(<<<'SQL'
            jsonb_set(self_week, '{0,lunch,name}', '42')
            SQL),
    ]);

    $repository->find(PlanImportId::fromString($import->id()->value()));
})->throws(UnexpectedValueException::class, 'Importación guardada corrupta: «self_week[0].lunch.name» debería ser un texto.');
