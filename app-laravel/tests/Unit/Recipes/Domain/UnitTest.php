<?php

/*
| Tests del enum Unit: el valor guardado (en inglés) y la etiqueta para el
| usuario (en español).
*/

use Recipes\Domain\Unit;

it('has a Spanish label for every unit', function (Unit $unit) {
    // Recorre TODOS los casos del enum (dataset con Unit::cases()): si alguien
    // añade una unidad sin etiqueta, este test lo detecta.
    expect($unit->label())->toBeString()->not->toBeEmpty();
})->with(Unit::cases());

it('uses the names of the glossary', function () {
    expect(Unit::Tablespoon->label())->toBe('cucharada sopera')
        ->and(Unit::Jar->label())->toBe('bote')
        ->and(Unit::Piece->label())->toBe('unidad');
});

it('is recovered from its stored value', function () {
    // El valor del backed enum es el que irá a la base de datos y a la API.
    expect(Unit::from('gram'))->toBe(Unit::Gram)
        ->and(Unit::tryFrom('gramo'))->toBeNull();
});
