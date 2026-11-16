<?php

/*
| Tests de contrato entre el servicio importer (Python) y Laravel (HEXM-0031).
|
| CONCEPTO: contract testing entre dos lenguajes (ADR 0024)
| El contrato vive en contracts/importer y lo genera el servicio desde sus
| modelos de Pydantic (`uv run poe contract`): un JSON Schema por respuesta
| y respuestas reales de ejemplo. Cada lado se prueba contra él por
| separado, sin levantar el otro:
|
|   Python: tests/test_contract.py falla si los modelos ya no generan el
|           contrato versionado (obliga a regenerarlo, y el diff se ve en el PR);
|   Laravel: estos tests fallan si el contrato regenerado ya no es lo que
|           Laravel sabe leer.
|
| Así, un cambio en un modelo de Pydantic (un campo nuevo, uno renombrado,
| un tipo distinto) no puede llegar a producción sin que algún test se
| ponga en rojo.
|
| CONCEPTO: JSON Schema
| Un estándar para describir la forma de un JSON (tipos, campos
| obligatorios, mínimos, si se admiten campos de más) con otro JSON. Pydantic
| lo genera desde sus modelos, y opis/json-schema (dependencia de
| desarrollo) lo valida en PHP. Es lo que permite que dos lenguajes
| compartan un contrato que ninguno de los dos «posee».
*/

use MealPlanning\Infrastructure\Importer\ImporterResponse;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Tests\Support\MealPlanning\ImporterContract;

it('has examples that conform to their JSON Schema', function (string $name) {
    // El validador de Opis espera el JSON decodificado como objetos (stdClass),
    // no como arrays asociativos: así distingue {} de [].
    $result = (new Validator)->validate(json_decode(ImporterContract::exampleBody($name)), ImporterContract::schema($name));

    // Si no es válido, el mensaje del test dice qué campo falla y por qué.
    $errors = $result->hasError() ? (new ErrorFormatter)->format($result->error()) : [];

    expect($errors)->toBe([], (string) json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
})->with([ImporterContract::NUTRITIONIST_PLAN, ImporterContract::SELF_WEEK, ImporterContract::INVALID_WORKBOOK]);

it('understands every example the service produces', function () {
    // El mapper del adaptador es estricto (faltan o sobran campos → error):
    // si el servicio cambiara su JSON y se regenerase el contrato, esto
    // fallaría hasta que Laravel lo entienda.
    expect(ImporterResponse::nutritionistPlan(ImporterContract::example(ImporterContract::NUTRITIONIST_PLAN))->number)->toBeInt()
        ->and(ImporterResponse::selfWeek(ImporterContract::example(ImporterContract::SELF_WEEK))->selfWeek)->toHaveCount(7)
        ->and(ImporterResponse::problems(ImporterContract::example(ImporterContract::INVALID_WORKBOOK)))->not->toBeEmpty();
});

it('only describes closed objects whose fields are all required', function (string $name) {
    // El mapper exige EXACTAMENTE los campos del contrato. Eso solo es
    // correcto si el schema dice lo mismo: todos los campos de cada objeto
    // del contrato llegan siempre (aunque sea a null) y no hay campos de
    // más. Si un modelo de Pydantic añadiera un campo opcional o dejara de
    // prohibir los extra, este test avisaría de que el mapper ya no encaja.
    /** @var array{type: string, properties: array<string, mixed>, required: list<string>, additionalProperties?: bool, '$defs'?: array<string, array{type: string, properties: array<string, mixed>, required: list<string>, additionalProperties?: bool}>} $schema */
    $schema = json_decode(ImporterContract::schema($name), true, flags: JSON_THROW_ON_ERROR);

    // Las definiciones de tipo «object» (los enums, como ExchangeCategory, son textos).
    $objects = array_filter(['$' => $schema, ...($schema['$defs'] ?? [])], fn (array $definition): bool => $definition['type'] === 'object');

    foreach ($objects as $object => $definition) {
        expect($definition['additionalProperties'] ?? true)->toBeFalse("«{$object}» admite campos de más")
            ->and($definition['required'])->toEqualCanonicalizing(array_keys($definition['properties']), "«{$object}» tiene campos opcionales");
    }
})->with([ImporterContract::NUTRITIONIST_PLAN, ImporterContract::SELF_WEEK]);
