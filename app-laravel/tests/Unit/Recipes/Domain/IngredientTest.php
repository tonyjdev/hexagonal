<?php

/*
| Tests de Ingredient: value object con nombre y cantidad opcional.
*/

use Recipes\Domain\Ingredient;
use Recipes\Domain\InvalidIngredient;
use Recipes\Domain\Quantity;
use Recipes\Domain\Unit;

it('has a name and a quantity', function () {
    $lentils = Ingredient::of('Lentejas', Quantity::fromString('½', Unit::Jar));

    expect($lentils->name())->toBe('Lentejas')
        ->and($lentils->quantity()?->equals(Quantity::fraction(1, 2, Unit::Jar)))->toBeTrue()
        ->and($lentils->isToTaste())->toBeFalse();
});

it('can be "to taste", without quantity', function () {
    // "Sal al gusto": hay ingrediente, pero no cantidad (decisión de HEXM-0005).
    $salt = Ingredient::toTaste('Sal');

    expect($salt->quantity())->toBeNull()
        ->and($salt->isToTaste())->toBeTrue();
});

it('normalizes the spaces of the name', function () {
    expect(Ingredient::toTaste("  aceite \t de   oliva ")->name())->toBe('aceite de oliva');
});

it('cannot have an empty name', function (string $name) {
    Ingredient::toTaste($name);
})->with(['', '   '])->throws(InvalidIngredient::class, 'Un ingrediente debe tener nombre.');

describe('comparison by value', function () {
    it('is equal to another ingredient with the same data', function () {
        // CONCEPTO: value object. Dos objetos distintos con los mismos datos
        // son el MISMO valor. Compáralo con RecipeTest: dos recetas con los
        // mismos datos pero distinto id NO son la misma receta.
        $rice = Ingredient::of('Arroz', Quantity::of(200, Unit::Gram));

        expect($rice->equals(Ingredient::of('arroz', Quantity::of(200, Unit::Gram))))->toBeTrue();
    });

    it('is different if the quantity is different', function () {
        expect(Ingredient::of('Arroz', Quantity::of(200, Unit::Gram))
            ->equals(Ingredient::of('Arroz', Quantity::of(100, Unit::Gram))))->toBeFalse()
            ->and(Ingredient::toTaste('Sal')->equals(Ingredient::of('Sal', Quantity::of(5, Unit::Gram))))->toBeFalse()
            ->and(Ingredient::toTaste('Sal')->equals(Ingredient::toTaste('sal')))->toBeTrue();
    });

    it('recognizes the same ingredient ignoring case, even with another quantity', function () {
        expect(Ingredient::of('Cebolla', Quantity::of(1, Unit::Piece))
            ->hasSameNameAs(Ingredient::of('CEBOLLA', Quantity::of(2, Unit::Piece))))->toBeTrue()
            ->and(Ingredient::toTaste('Ñora')->hasSameNameAs(Ingredient::toTaste('ñora')))->toBeTrue()
            ->and(Ingredient::toTaste('Cebolla')->hasSameNameAs(Ingredient::toTaste('Cebolleta')))->toBeFalse();
    });
});
