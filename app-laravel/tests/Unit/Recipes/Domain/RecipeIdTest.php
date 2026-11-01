<?php

/*
| Tests de RecipeId: identificador UUID v7 generado en el dominio.
*/

use Recipes\Domain\InvalidRecipeId;
use Recipes\Domain\RecipeId;

it('generates a well-formed UUID version 7', function () {
    $id = RecipeId::generate()->value();

    // Versión 7 en el tercer bloque y variante RFC (8, 9, a o b) en el cuarto.
    expect($id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
});

it('generates a different identifier each time', function () {
    $ids = array_map(fn () => RecipeId::generate()->value(), range(1, 1000));

    expect(array_unique($ids))->toHaveCount(1000);
});

it('starts with the creation time, so identifiers are ordered by date', function () {
    // Los 12 primeros caracteres hexadecimales (48 bits) son los milisegundos
    // Unix en que se creó. Por eso un UUID v7 posterior es "mayor".
    $before = (int) floor(microtime(true) * 1000);
    $id = RecipeId::generate()->value();
    $after = (int) floor(microtime(true) * 1000);

    $milliseconds = (int) hexdec(str_replace('-', '', substr($id, 0, 13)));

    expect($milliseconds)->toBeGreaterThanOrEqual($before)->toBeLessThanOrEqual($after);
});

it('is rebuilt from an existing identifier, normalized to lowercase', function () {
    $id = RecipeId::fromString(' 019A3B2C-7D4E-7F00-8A1B-2C3D4E5F6A7B ');

    expect($id->value())->toBe('019a3b2c-7d4e-7f00-8a1b-2c3d4e5f6a7b')
        ->and($id->equals(RecipeId::fromString('019a3b2c-7d4e-7f00-8a1b-2c3d4e5f6a7b')))->toBeTrue();
});

it('accepts valid UUIDs of other versions', function () {
    // Un identificador válido puede venir de fuera (por ejemplo, un v4).
    expect(RecipeId::fromString('f47ac10b-58cc-4372-a567-0e02b2c3d479')->value())
        ->toBe('f47ac10b-58cc-4372-a567-0e02b2c3d479');
});

it('rejects malformed identifiers', function (string $value) {
    RecipeId::fromString($value);
})->with([
    'vacío' => '',
    'texto' => 'receta-1',
    'autoincremental' => '42',
    'sin guiones' => '019a3b2c7d4e7f008a1b2c3d4e5f6a7b',
    'versión 0' => '019a3b2c-7d4e-0f00-8a1b-2c3d4e5f6a7b',
    'variante incorrecta' => '019a3b2c-7d4e-7f00-c01b-2c3d4e5f6a7b',
    'carácter no hexadecimal' => '019a3b2c-7d4e-7f00-8a1b-2c3d4e5f6a7g',
])->throws(InvalidRecipeId::class, 'no es un identificador de receta válido');

it('is compared by value', function () {
    $id = RecipeId::generate();

    expect($id->equals(RecipeId::fromString($id->value())))->toBeTrue()
        ->and($id->equals(RecipeId::generate()))->toBeFalse();
});
