<?php

/*
| Tests de Servings: una receta da al menos una ración.
*/

use Recipes\Domain\InvalidServings;
use Recipes\Domain\Servings;

it('keeps the number of servings', function () {
    expect(Servings::of(4)->value())->toBe(4);
});

it('cannot be zero or negative', function (int $value) {
    Servings::of($value);
})->with([0, -1])->throws(InvalidServings::class, 'Una receta debe tener al menos una ración.');

it('is compared by value', function () {
    // Dos objetos distintos en memoria, pero el mismo valor: son iguales.
    expect(Servings::of(2)->equals(Servings::of(2)))->toBeTrue()
        ->and(Servings::of(2)->equals(Servings::of(3)))->toBeFalse();
});
