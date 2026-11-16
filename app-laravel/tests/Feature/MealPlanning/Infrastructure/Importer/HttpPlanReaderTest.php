<?php

/*
| Tests del adaptador HttpPlanReader (HEXM-0031): lo que es propio de HTTP y
| no comparte con el fake.
|
| Lo común a los dos adaptadores (leer, rechazar, no disponible) está en
| PlanReaderContractTest. Aquí: cómo se envía la petición (URL de la
| configuración, multipart, campo `file`) y cómo se traduce cada fallo de la
| red o del servicio a los errores del puerto, sin perder el error técnico
| original (el `previous`, que es lo que sale en el log).
|
| CONCEPTO: Http::fake()
| Sustituye las respuestas del cliente HTTP de Laravel: ninguna petición sale
| de la máquina. Se le da una respuesta por patrón de URL (o una closure que
| recibe la petición) y después Http::assertSent() comprueba qué se envió.
| Es el equivalente, para un servicio externo, a usar un repositorio en
| memoria en lugar de la base de datos.
*/

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MealPlanning\Application\PlanDocument;
use MealPlanning\Application\PlanReader;
use MealPlanning\Application\PlanReaderUnavailable;
use MealPlanning\Application\UnreadableDocument;
use MealPlanning\Infrastructure\Importer\HttpPlanReader;
use MealPlanning\Infrastructure\Importer\InvalidImporterResponse;
use Tests\Support\MealPlanning\ImporterContract;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.importer.url' => 'http://importer.test']);
});

/**
 * Ejecuta una lectura que debe fallar y devuelve la excepción, para poder
 * mirar su `previous`.
 */
function failedReading(callable $read): Throwable
{
    try {
        $read(app(PlanReader::class));
    } catch (Throwable $exception) {
        return $exception;
    }

    throw new LogicException('Se esperaba que la lectura fallara.');
}

it('is the adapter the application uses for the plan reader port', function () {
    expect(app(PlanReader::class))->toBeInstanceOf(HttpPlanReader::class);
});

it('uploads each document as multipart to its route in the configured service', function (string $read, string $route, string $example) {
    Http::fake(['*' => Http::response(ImporterContract::exampleBody($example))]);

    app(PlanReader::class)->{$read}(new PlanDocument('mi-plan.xlsx', 'bytes del excel'));

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === "http://importer.test{$route}"
        && $request->isMultipart()
        // El campo que espera el UploadFile de FastAPI, con el nombre y el contenido.
        && $request->hasFile('file', 'bytes del excel', 'mi-plan.xlsx')
        && $request->hasHeader('Accept', 'application/json'));
})->with([
    'nutritionist excel' => ['readNutritionistPlan', '/nutritionist-plans/read', ImporterContract::NUTRITIONIST_PLAN],
    'self week excel' => ['readSelfWeek', '/self-weeks/read', ImporterContract::SELF_WEEK],
]);

it('translates a 422 of the service into an unreadable document', function () {
    Http::fake(['*' => Http::response(ImporterContract::exampleBody(ImporterContract::INVALID_WORKBOOK), 422)]);

    expect(fn () => app(PlanReader::class)->readSelfWeek(new PlanDocument('semana-2.xlsx', 'x')))
        ->toThrow(UnreadableDocument::class, 'El documento «semana-2.xlsx» no tiene la forma esperada: Hoja «Detalle»: falta la hoja · Hoja «Resumen»: falta la hoja');
});

it('translates a timeout into an unavailable reader, keeping the technical cause', function () {
    // Lo que lanza el cliente de Laravel cuando el servicio no responde a tiempo.
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds'));

    $exception = failedReading(fn (PlanReader $reader) => $reader->readNutritionistPlan(new PlanDocument('plan.xlsx', 'x')));

    expect($exception)->toBeInstanceOf(PlanReaderUnavailable::class)
        ->and($exception->getPrevious())->toBeInstanceOf(ConnectionException::class)
        ->and($exception->getPrevious()?->getMessage())->toContain('timed out');
});

it('translates an internal error of the service into an unavailable reader', function () {
    Http::fake(['*' => Http::response('Internal Server Error', 500)]);

    $exception = failedReading(fn (PlanReader $reader) => $reader->readSelfWeek(new PlanDocument('plan.xlsx', 'x')));

    expect($exception)->toBeInstanceOf(PlanReaderUnavailable::class)
        ->and($exception->getPrevious()?->getMessage())->toBe('El importador respondió 500 a POST /self-weeks/read: Internal Server Error');
});

it('treats a response that breaks the contract as an unavailable reader', function (Closure $breakContract, string $where) {
    // Un 200 que no tiene la forma acordada: dos versiones que no se
    // entienden. El usuario no puede arreglarlo (503) y el log dice dónde.
    $example = ImporterContract::example(ImporterContract::NUTRITIONIST_PLAN);
    Http::fake(['*' => Http::response($breakContract($example))]);

    $exception = failedReading(fn (PlanReader $reader) => $reader->readNutritionistPlan(new PlanDocument('plan.xlsx', 'x')));

    expect($exception)->toBeInstanceOf(PlanReaderUnavailable::class)
        ->and($exception->getPrevious())->toBeInstanceOf(InvalidImporterResponse::class)
        ->and($exception->getPrevious()?->getMessage())->toContain($where);
})->with([
    'a missing field' => [fn (array $plan) => array_diff_key($plan, ['exchange_rules' => true]), '«$»: faltan los campos exchange_rules'],
    'an unknown field' => [fn (array $plan) => [...$plan, 'starts_on' => '2026-11-16'], '«$»: sobran los campos starts_on'],
    'a renamed nested field' => [function (array $plan) {
        /** @var array{nutritionist_week: list<array<string, mixed>>} $plan */
        $plan['nutritionist_week'][2] = ['menu' => 3, 'comida' => 'Lentejas', 'dinner' => 'Sopa'];

        return $plan;
    }, '«nutritionist_week.2»: faltan los campos lunch'],
    'a type that changes' => [fn (array $plan) => [...$plan, 'number' => '99'], '«number»: debía ser un número entero'],
    'a list that becomes an object' => [fn (array $plan) => [...$plan, 'exchanges' => ['arroz' => '1/3 vaso']], '«exchanges»: debía ser una lista'],
]);

it('treats a body that is not JSON as an unavailable reader', function () {
    Http::fake(['*' => Http::response('<html>Bad Gateway</html>', 200)]);

    expect(fn () => app(PlanReader::class)->readSelfWeek(new PlanDocument('plan.xlsx', 'x')))
        ->toThrow(PlanReaderUnavailable::class);
});

it('treats a 422 that is not an unreadable excel as an unavailable reader', function () {
    // El 422 genérico de FastAPI ({"detail": …}) significa que NUESTRA
    // petición estaba mal (sin el campo `file`): no es culpa del documento.
    Http::fake(['*' => Http::response(['detail' => [['loc' => ['body', 'file'], 'msg' => 'Field required']]], 422)]);

    $exception = failedReading(fn (PlanReader $reader) => $reader->readSelfWeek(new PlanDocument('plan.xlsx', 'x')));

    expect($exception)->toBeInstanceOf(PlanReaderUnavailable::class)
        ->and($exception->getPrevious())->toBeInstanceOf(InvalidImporterResponse::class);
});
