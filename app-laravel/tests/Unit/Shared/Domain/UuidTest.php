<?php

/*
| Tests de Uuid: generación (v7) y lectura de UUID, en el shared kernel.
|
| Son los tests técnicos del formato que antes estaban solo en RecipeIdTest.
| RecipeIdTest no se ha tocado: sigue comprobando lo mismo a través de
| RecipeId, y así demuestra que la extracción no ha cambiado su
| comportamiento.
*/

use Shared\Domain\Uuid;

it('generates a well-formed UUID version 7', function () {
    // Versión 7 en el tercer bloque y variante RFC (8, 9, a o b) en el cuarto.
    expect(Uuid::v7()->value())
        ->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
});

it('generates a different UUID each time', function () {
    $uuids = array_map(fn () => Uuid::v7()->value(), range(1, 1000));

    expect(array_unique($uuids))->toHaveCount(1000);
});

it('starts with the creation time in milliseconds', function () {
    $before = (int) floor(microtime(true) * 1000);
    $uuid = Uuid::v7()->value();
    $after = (int) floor(microtime(true) * 1000);

    $milliseconds = (int) hexdec(str_replace('-', '', substr($uuid, 0, 13)));

    expect($milliseconds)->toBeGreaterThanOrEqual($before)->toBeLessThanOrEqual($after);
});

it('reads an existing UUID of any version, normalized to lowercase', function () {
    expect(Uuid::tryFromString(' 019A3B2C-7D4E-7F00-8A1B-2C3D4E5F6A7B ')?->value())
        ->toBe('019a3b2c-7d4e-7f00-8a1b-2c3d4e5f6a7b')
        ->and(Uuid::tryFromString('f47ac10b-58cc-4372-a567-0e02b2c3d479')?->value())
        ->toBe('f47ac10b-58cc-4372-a567-0e02b2c3d479');
});

it('returns null instead of throwing for malformed text', function (string $value) {
    // Uuid no decide el error: lo hace el identificador de negocio que lo
    // envuelve (InvalidRecipeId, InvalidNutritionPlanId...).
    expect(Uuid::tryFromString($value))->toBeNull();
})->with([
    'vacío' => '',
    'texto' => 'plan-27',
    'sin guiones' => '019a3b2c7d4e7f008a1b2c3d4e5f6a7b',
    'versión 0' => '019a3b2c-7d4e-0f00-8a1b-2c3d4e5f6a7b',
    'variante incorrecta' => '019a3b2c-7d4e-7f00-c01b-2c3d4e5f6a7b',
]);

it('is compared by value', function () {
    $uuid = Uuid::v7();
    $sameInUppercase = Uuid::tryFromString(strtoupper($uuid->value()));
    assert($sameInUppercase instanceof Uuid);

    expect($uuid->equals($sameInUppercase))->toBeTrue()
        ->and($uuid->equals(Uuid::v7()))->toBeFalse();
});
