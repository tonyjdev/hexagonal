<?php

/*
| Tests de Step: un paso de preparación con texto.
*/

use Recipes\Domain\InvalidStep;
use Recipes\Domain\Step;

it('keeps the text without surrounding spaces', function () {
    expect(Step::fromString('  Sofreír la cebolla 5 minutos ')->text())->toBe('Sofreír la cebolla 5 minutos');
});

it('cannot be empty', function (string $text) {
    Step::fromString($text);
})->with(['', '   ', "\n\t"])->throws(InvalidStep::class, 'Un paso de preparación no puede estar vacío.');

it('is compared by value', function () {
    // La normalización hace que los espacios sobrantes no cuenten.
    expect(Step::fromString('Batir los huevos')->equals(Step::fromString(' Batir los huevos ')))->toBeTrue()
        ->and(Step::fromString('Batir los huevos')->equals(Step::fromString('Freír los huevos')))->toBeFalse();
});
