<?php

/*
| Tests de la API de la importación de un plan (HEXM-0033):
|
|   POST /api/plan-imports                          Excel de la nutricionista
|   PUT  /api/plan-imports/{id}/self-week           Excel de la semana 2
|   PUT  /api/plan-imports/{id}/nutritionist-plan   sustituir el de la nutricionista
|   POST /api/plan-imports/{id}/confirm             fecha de inicio → plan
|   GET  /api/plan-imports/{id}                     qué tiene y qué le falta
|
| Recorren la petición entera (rutas → Form Request → controlador → caso de
| uso → EloquentPlanImportRepository/EloquentNutritionPlanRepository →
| PostgreSQL) salvo el lector de Excel.
|
| CONCEPTO: la pirámide de tests, con el fake en la suite normal
| El puerto PlanReader se sustituye en el contenedor por InMemoryPlanReader,
| que devuelve las lecturas del contrato versionado (contracts/importer: lo
| que responde de verdad el servicio con los Excel ficticios del plan 99).
| Así estos tests no necesitan el servicio Python levantado, son rápidos y no
| fallan por motivos ajenos a Laravel. Que el adaptador real hable bien con el
| servicio lo demuestran los tests de contrato (HEXM-0031) y, una sola vez, la
| prueba de punta a punta del grupo `e2e` (PlanImportEndToEndTest).
|
| Por eso el contenido de los ficheros subidos da igual aquí: el fake no los
| lee. Lo que se comprueba es lo que es de Laravel: la forma de la petición,
| los códigos de estado y que la importación y el plan se guardan.
|
| Plan 99, inicio el lunes 02/11/2026.
*/

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use MealPlanning\Application\PlanReader;
use MealPlanning\Infrastructure\InMemoryPlanReader;
use Tests\Support\MealPlanning\ImporterContract;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->app->instance(PlanReader::class, InMemoryPlanReader::returning(
        ImporterContract::nutritionistPlanReading(),
        ImporterContract::selfWeekReading(),
    ));
});

function excel(string $name = 'plan-99.xlsx'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, 'contenido de un Excel (el fake no lo lee)');
}

/**
 * Empieza una importación por la API y devuelve su id.
 */
function startImport(): string
{
    $id = test()->postJson('/api/plan-imports', ['file' => excel()])->assertCreated()->json('data.id');
    assert(is_string($id));

    return $id;
}

function attachSelfWeek(string $id): TestResponse
{
    return test()->putJson("/api/plan-imports/{$id}/self-week", ['file' => excel('semana-2.xlsx')]);
}

// --- El camino feliz (criterio de aceptación 1) ---------------------------------------

it('imports a plan from its two excels and the plan answers what is on the menu', function () {
    // 1. El Excel de la nutricionista: 201, la importación y dónde consultarla.
    $started = $this->postJson('/api/plan-imports', ['file' => excel()]);
    $id = $started->json('data.id');

    $started->assertCreated()
        ->assertHeader('Location', route('plan-imports.show', ['id' => $id]))
        ->assertJsonPath('data.number', 99)
        ->assertJsonPath('data.status', 'awaiting_self_week')
        ->assertJsonPath('data.status_label', 'pendiente de la semana 2')
        ->assertJsonPath('data.has_self_week', false)
        ->assertJsonPath('data.plan_id', null);

    // 2. El Excel de la semana 2: lista para confirmar.
    attachSelfWeek($id)
        ->assertOk()
        ->assertJsonPath('data.status', 'ready_to_confirm')
        ->assertJsonPath('data.has_self_week', true);

    // 3. Confirmar con la fecha de inicio: 201 y el plan, como en el alta manual.
    $confirmed = $this->postJson("/api/plan-imports/{$id}/confirm", ['starts_on' => '2026-11-02']);
    $planId = $confirmed->json('id');

    $confirmed->assertCreated()
        ->assertHeader('Location', route('nutrition-plans.show', ['id' => $planId]));

    // La importación queda confirmada y apunta al plan.
    $this->getJson("/api/plan-imports/{$id}")
        ->assertOk()
        ->assertExactJson(['data' => [
            'id' => $id,
            'number' => 99,
            'status' => 'confirmed',
            'status_label' => 'confirmada',
            'has_self_week' => true,
            'plan_id' => $planId,
            'plan_url' => route('nutrition-plans.show', ['id' => $planId]),
            'url' => route('plan-imports.show', ['id' => $id]),
        ]]);

    // 4. «¿Qué me toca?»: el plan importado responde en las dos semanas.
    // Martes 03/11, semana 1: el menú 2 de la nutricionista.
    $this->getJson('/api/menu?date=2026-11-03')
        ->assertOk()
        ->assertJsonPath('data.plan_number', 99)
        ->assertJsonPath('data.week_number', 1)
        ->assertJsonPath('data.lunch.description', 'Arroz (1/3 vaso en crudo) con verduras y pollo (2 filetes)');

    // Lunes 09/11, semana 2: el día 8 del usuario.
    $this->getJson('/api/menu?date=2026-11-09')
        ->assertOk()
        ->assertJsonPath('data.week_number', 2)
        ->assertJsonPath('data.lunch.name', 'Lentejas estofadas')
        ->assertJsonPath('data.dinner.name', 'Tortilla de calabacín');

    // Sábado 14/11: día libre (el día 13 del Excel, vacío).
    $this->getJson('/api/menu?date=2026-11-14')
        ->assertOk()
        ->assertJsonPath('data.is_free_day', true);
});

it('replaces the nutritionist excel of a pending import and keeps its self week', function () {
    $id = startImport();
    attachSelfWeek($id)->assertOk();

    $this->putJson("/api/plan-imports/{$id}/nutritionist-plan", ['file' => excel('plan-99-corregido.xlsx')])
        ->assertOk()
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.status', 'ready_to_confirm');
});

// --- La forma de la petición (Form Requests) ------------------------------------------

it('only accepts an xlsx file', function (array $payload) {
    $this->postJson('/api/plan-imports', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');

    $this->assertDatabaseCount('plan_imports', 0);
})->with([
    'no file' => [[]],
    'a pdf' => [fn () => ['file' => UploadedFile::fake()->createWithContent('plan-99.pdf', '%PDF-1.7')]],
    'an old xls' => [fn () => ['file' => UploadedFile::fake()->createWithContent('plan-99.xls', 'xls')]],
    'text instead of a file' => [['file' => 'plan-99.xlsx']],
    'a file over 5 MB' => [fn () => ['file' => UploadedFile::fake()->create('plan-99.xlsx', 5 * 1024 + 1)]],
]);

it('requires a start date with the format YYYY-MM-DD to confirm', function (array $payload) {
    $id = startImport();
    attachSelfWeek($id)->assertOk();

    $this->postJson("/api/plan-imports/{$id}/confirm", $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('starts_on');
})->with([
    'missing' => [[]],
    'another format' => [['starts_on' => '02/11/2026']],
    'not a date' => [['starts_on' => 'el lunes']],
]);

// --- Errores (criterio de aceptación 2) -----------------------------------------------

it('rejects an excel without the expected shape with every problem, in spanish (422)', function () {
    $this->app->instance(PlanReader::class, InMemoryPlanReader::rejecting(
        'Hoja «Menús», celda A1: falta la cabecera «Menú».',
        'Falta la hoja «Intercambios».',
    ));

    $this->postJson('/api/plan-imports', ['file' => excel('otro-excel.xlsx')])
        ->assertUnprocessable()
        ->assertExactJson(['message' => 'El documento «otro-excel.xlsx» no tiene la forma esperada: '
            .'Hoja «Menús», celda A1: falta la cabecera «Menú». · Falta la hoja «Intercambios».']);

    $this->assertDatabaseCount('plan_imports', 0);
});

it('rejects confirming without the self week, and creates no plan (422)', function () {
    $id = startImport();

    $this->postJson("/api/plan-imports/{$id}/confirm", ['starts_on' => '2026-11-02'])
        ->assertUnprocessable()
        ->assertExactJson(['message' => 'La importación del plan 99 no se puede confirmar: falta el Excel de la semana 2.']);

    $this->assertDatabaseCount('nutrition_plans', 0);
    $this->getJson("/api/plan-imports/{$id}")->assertJsonPath('data.status', 'awaiting_self_week');
});

it('applies the rules of the plan when confirming: it starts on a Monday (422)', function () {
    $id = startImport();
    attachSelfWeek($id)->assertOk();

    // Forma correcta (la Form Request la deja pasar), regla de negocio incumplida.
    $this->postJson("/api/plan-imports/{$id}/confirm", ['starts_on' => '2026-11-03'])
        ->assertUnprocessable()
        ->assertExactJson(['message' => 'Un plan empieza siempre en lunes, y el 03/11/2026 es martes.']);

    $this->assertDatabaseCount('nutrition_plans', 0);
});

it('cannot confirm an import twice (422)', function () {
    $id = startImport();
    attachSelfWeek($id)->assertOk();
    $this->postJson("/api/plan-imports/{$id}/confirm", ['starts_on' => '2026-11-02'])->assertCreated();

    $this->postJson("/api/plan-imports/{$id}/confirm", ['starts_on' => '2026-11-09'])
        ->assertUnprocessable()
        ->assertJsonPath('message', fn (string $message) => str_starts_with($message, 'La importación del plan 99 ya está confirmada'));

    $this->assertDatabaseCount('nutrition_plans', 1);
});

it('says when the import does not exist (404)', function (string $method, string $uri, array $payload) {
    $this->json($method, sprintf($uri, '0196f1a0-0000-7000-8000-000000000000'), $payload)
        ->assertNotFound()
        ->assertExactJson(['message' => 'No existe ninguna importación de plan con el identificador «0196f1a0-0000-7000-8000-000000000000».']);
})->with([
    'querying it' => ['GET', '/api/plan-imports/%s', []],
    'attaching the self week' => ['PUT', '/api/plan-imports/%s/self-week', fn () => ['file' => excel()]],
    'replacing the nutritionist excel' => ['PUT', '/api/plan-imports/%s/nutritionist-plan', fn () => ['file' => excel()]],
    'confirming it' => ['POST', '/api/plan-imports/%s/confirm', ['starts_on' => '2026-11-02']],
]);

it('rejects a malformed import id as a domain error (422)', function () {
    $this->getJson('/api/plan-imports/no-es-un-uuid')
        ->assertUnprocessable()
        ->assertJsonStructure(['message']);
});

it('answers 503 when the importer service is down, and creates nothing', function () {
    $this->app->instance(PlanReader::class, InMemoryPlanReader::unavailable());

    $this->postJson('/api/plan-imports', ['file' => excel()])
        ->assertServiceUnavailable()
        ->assertJsonStructure(['message']);

    $this->assertDatabaseCount('plan_imports', 0);
});
