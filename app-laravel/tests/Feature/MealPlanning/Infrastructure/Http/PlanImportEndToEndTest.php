<?php

/*
| Prueba de punta a punta de la importación de un plan (HEXM-0033), con el
| servicio Python REAL:
|
|   test → POST /api/plan-imports → StartPlanImport → HttpPlanReader
|        → HTTP multipart → FastAPI (services/importer) → openpyxl lee el Excel
|        → JSON → ImporterResponse → PlanImport → PostgreSQL → … → GET /api/menu
|
| CONCEPTO: la pirámide de tests
|
|          /\        punta a punta: 1 test, lento, necesita el servicio
|         /  \       contrato: el JSON de los dos lados contra contracts/importer
|        /____\      unitarios y de feature con fakes: cientos, rápidos
|
| Los tests de abajo dicen que cada pieza hace lo suyo; este dice que las
| piezas encajan de verdad: que el fichero viaja entero por multipart, que la
| URL y el timeout de config/services.php son los buenos y que lo que lee
| Python de los Excel ficticios acaba siendo un plan que responde a
| «¿qué me toca?». Es caro y depende de otro proceso, por eso hay UNO, que
| recorre el camino feliz y el error más típico; los demás casos ya están
| cubiertos con el fake (PlanImportEndpointsTest).
|
| Cuándo se ejecuta:
|   - Siempre que se ejecuta la suite (también en el gate), pero si el
|     servicio no responde en /health se SALTA (skipped): la suite normal no
|     depende de que haya un proceso Python levantado.
|   - `composer test:e2e` ejecuta solo este grupo (`--group=e2e`) y pone
|     IMPORTER_E2E_REQUIRED=1: ahí, si el servicio no responde, el test
|     FALLA. Quien pide expresamente la prueba de punta a punta no quiere un
|     verde que en realidad no ha probado nada.
|
| Para ejecutarlo:
|   (cd services/importer && uv run poe serve)   # en otra terminal
|   (cd app-laravel && composer test:e2e)
|
| Los Excel son los ficticios del servicio (services/importer/tests/fixtures),
| los mismos con los que se generaron los ejemplos del contrato: plan 99.
*/

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class)->group('e2e');

beforeEach(function () {
    $url = Config::string('services.importer.url');

    try {
        $available = Http::timeout(1)->get($url.'/health')->successful();
    } catch (Throwable) {
        $available = false;
    }

    if ($available) {
        return;
    }

    $message = "El servicio importer no responde en {$url}/health. Levántalo con `(cd services/importer && uv run poe serve)`.";

    if (getenv('IMPORTER_E2E_REQUIRED') === '1') {
        $this->fail($message);
    }

    $this->markTestSkipped($message);
});

/**
 * Uno de los Excel ficticios del servicio, como si lo subiera un usuario.
 */
function fixtureExcel(string $name): UploadedFile
{
    $path = base_path("../services/importer/tests/fixtures/{$name}");

    // $test = true: el fichero no ha llegado por una subida real de PHP, y
    // sin esa marca Laravel lo daría por no válido (is_uploaded_file()).
    return new UploadedFile($path, $name, null, null, true);
}

it('imports the fictitious plan from its two real excels through the importer service', function () {
    $started = $this->postJson('/api/plan-imports', ['file' => fixtureExcel('plan-nutricionista-ficticio.xlsx')])
        ->assertCreated()
        ->assertJsonPath('data.number', 99)
        ->assertJsonPath('data.status', 'awaiting_self_week');
    $id = $started->json('data.id');

    $this->putJson("/api/plan-imports/{$id}/self-week", ['file' => fixtureExcel('semana-2-ficticia.xlsx')])
        ->assertOk()
        ->assertJsonPath('data.status', 'ready_to_confirm');

    $this->postJson("/api/plan-imports/{$id}/confirm", ['starts_on' => '2026-11-02'])
        ->assertCreated();

    // Martes 03/11, semana 1: el menú 2 tal como lo escribió la nutricionista.
    $this->getJson('/api/menu?date=2026-11-03')
        ->assertOk()
        ->assertJsonPath('data.plan_number', 99)
        ->assertJsonPath('data.week_number', 1)
        ->assertJsonPath('data.lunch.description', 'Arroz (1/3 vaso en crudo) con verduras y pollo (2 filetes)');

    // Lunes 09/11, semana 2: el día 8, con los ingredientes leídos del Excel.
    $this->getJson('/api/menu?date=2026-11-09')
        ->assertOk()
        ->assertJsonPath('data.week_number', 2)
        ->assertJsonPath('data.lunch.name', 'Lentejas estofadas')
        ->assertJsonPath('data.lunch.ingredients.0.name', '½ bote de lentejas');
});

it('rejects the wrong excel with the problems found by the importer service, in spanish', function () {
    // El Excel de la semana 2 donde se espera el de la nutricionista: el
    // servicio no encuentra sus hojas y responde 422 con cada problema, que
    // HttpPlanReader traduce a UnreadableDocument.
    $this->postJson('/api/plan-imports', ['file' => fixtureExcel('semana-2-ficticia.xlsx')])
        ->assertUnprocessable()
        ->assertJsonPath('message', fn (string $message) => str_starts_with($message, 'El documento «semana-2-ficticia.xlsx» no tiene la forma esperada: '));

    $this->assertDatabaseCount('plan_imports', 0);
});
