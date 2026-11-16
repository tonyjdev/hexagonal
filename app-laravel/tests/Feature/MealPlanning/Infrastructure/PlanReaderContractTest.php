<?php

/*
| Tests de contrato del puerto PlanReader (HEXM-0031).
|
| CONCEPTO: tests de contrato de un puerto (como RecipeCatalogContractTest)
| La misma batería contra los dos adaptadores:
|   - InMemoryPlanReader: el fake con el que se probarán los casos de uso de
|     la importación (HEXM-0032, HEXM-0033) sin el servicio Python;
|   - HttpPlanReader: el adaptador real, resuelto del contenedor, con el
|     servicio importer simulado por Http::fake() con las respuestas del
|     contrato versionado (contracts/importer), que son respuestas reales
|     del servicio.
| Los dos tienen que leer lo mismo y fallar igual. Así, lo que demuestren
| los tests rápidos con el fake vale también con el servicio de verdad.
|
| Cada adaptador del dataset es una FÁBRICA que recibe el comportamiento
| que tiene que simular: leer bien, rechazar el documento o estar caído.
| Http::preventStrayRequests() garantiza que ningún test llega a la red: si
| una petición no tiene respuesta simulada, el test falla.
*/

use Illuminate\Support\Facades\Http;
use MealPlanning\Application\PlanDocument;
use MealPlanning\Application\PlanReader;
use MealPlanning\Application\PlanReaderUnavailable;
use MealPlanning\Application\UnreadableDocument;
use MealPlanning\Infrastructure\InMemoryPlanReader;
use Tests\Support\MealPlanning\ImporterContract;

dataset('plan readers', [
    'in memory' => fn (string $behaviour): PlanReader => match ($behaviour) {
        'reads' => InMemoryPlanReader::returning(ImporterContract::nutritionistPlanReading(), ImporterContract::selfWeekReading()),
        'rejects' => InMemoryPlanReader::rejecting(...ImporterContract::problems()),
        'is unavailable' => InMemoryPlanReader::unavailable(),
    },
    'http, with the importer faked' => function (string $behaviour): PlanReader {
        Http::preventStrayRequests();
        Http::fake(match ($behaviour) {
            'reads' => [
                '*/nutritionist-plans/read' => Http::response(ImporterContract::exampleBody(ImporterContract::NUTRITIONIST_PLAN)),
                '*/self-weeks/read' => Http::response(ImporterContract::exampleBody(ImporterContract::SELF_WEEK)),
            ],
            'rejects' => ['*' => Http::response(ImporterContract::exampleBody(ImporterContract::INVALID_WORKBOOK), 422)],
            // Como si no hubiera nada escuchando en la URL del servicio.
            'is unavailable' => ['*' => Http::failedConnection()],
        });

        // Del contenedor, como en la aplicación: comprueba también el binding.
        return app(PlanReader::class);
    },
]);

function planDocument(): PlanDocument
{
    return new PlanDocument('plan-27.xlsx', 'contenido binario del Excel');
}

it('reads the nutritionist excel with the shape of the plan contract', function (Closure $reader) {
    $reading = $reader('reads')->readNutritionistPlan(planDocument());

    // Campo a campo, lo mismo que la respuesta real del servicio: el contrato
    // del alta de un plan (ADR 0014) llega sin perder ni cambiar nada.
    $example = ImporterContract::example(ImporterContract::NUTRITIONIST_PLAN);
    expect($reading->number)->toBe($example['number'])
        ->and($reading->fixedMeals)->toBe($example['fixed_meals'])
        ->and($reading->exchanges)->toBe($example['exchanges'])
        ->and($reading->exchangeRules)->toBe($example['exchange_rules'])
        ->and($reading->nutritionistWeek)->toBe($example['nutritionist_week'])
        ->and($reading->nutritionistWeek)->toHaveCount(6);
})->with('plan readers');

it('reads the self week excel with its saturday empty and the whole ingredient text', function (Closure $reader) {
    $reading = $reader('reads')->readSelfWeek(planDocument());

    expect($reading->selfWeek)->toBe(ImporterContract::example(ImporterContract::SELF_WEEK)['self_week'])
        ->and(array_column($reading->selfWeek, 'day'))->toBe([8, 9, 10, 11, 12, 13, 14])
        ->and($reading->selfWeek[5])->toBe(['day' => 13, 'lunch' => null, 'dinner' => null])
        // La cantidad no se separa hasta el nivel 9 (ADR 0023).
        ->and($reading->selfWeek[0]['lunch']['ingredients'][0] ?? [])->toHaveKey('quantity', null);
})->with('plan readers');

it('rejects a malformed document with all its problems, in Spanish', function (Closure $reader, string $read) {
    try {
        $reader('rejects')->{$read}(planDocument());
        $this->fail('Se esperaba UnreadableDocument.');
    } catch (UnreadableDocument $exception) {
        expect($exception->problems())->toBe(ImporterContract::problems())
            ->and($exception->getMessage())->toStartWith('El documento «plan-27.xlsx» no tiene la forma esperada: ')
            ->and($exception->getMessage())->toContain(...ImporterContract::problems());
    }
})->with('plan readers')->with(['readNutritionistPlan', 'readSelfWeek']);

it('says that the reader is unavailable when it cannot read', function (Closure $reader, string $read) {
    expect(fn () => $reader('is unavailable')->{$read}(planDocument()))
        ->toThrow(PlanReaderUnavailable::class, 'Ahora mismo no se pueden leer los documentos del plan. Inténtalo de nuevo en unos minutos.');
})->with('plan readers')->with(['readNutritionistPlan', 'readSelfWeek']);
