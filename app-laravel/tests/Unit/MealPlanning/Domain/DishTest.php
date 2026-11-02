<?php

/*
| Tests de Dish y DishIngredient: un plato admite los dos formatos de los
| planes (semana 1 en texto libre, semana 2 con nombre e ingredientes) y
| nunca puede estar vacío.
*/

use MealPlanning\Domain\Dish;
use MealPlanning\Domain\DishIngredient;
use MealPlanning\Domain\InvalidDish;

it('accepts a week 1 dish written as free text', function () {
    // Así llegan los menús de la nutricionista: una sola frase descriptiva.
    $dish = Dish::described('  Lentejas (½ bote) con pisto de calabacín, cebolla y tomate ');

    expect($dish->description())->toBe('Lentejas (½ bote) con pisto de calabacín, cebolla y tomate')
        ->and($dish->name())->toBeNull()
        ->and($dish->ingredients())->toBe([]);
});

it('keeps the line breaks of a free text description', function () {
    expect(Dish::described("Lentejas (½ bote)\ncon pisto")->description())->toBe("Lentejas (½ bote)\ncon pisto");
});

it('accepts a week 2 dish with a short name and its ingredients', function () {
    // Así los apunta el usuario en su semana 2: nombre + lista con cantidades.
    $dish = Dish::named('Pasta  con salmón y pesto', [
        DishIngredient::of('Pasta', '80 g'),
        DishIngredient::of('Salmón', '100 g'),
        DishIngredient::of('Pesto'),
    ]);

    expect($dish->name())->toBe('Pasta con salmón y pesto')
        ->and($dish->description())->toBeNull()
        ->and(array_map(fn (DishIngredient $i) => [$i->name(), $i->quantity()], $dish->ingredients()))
        ->toBe([['Pasta', '80 g'], ['Salmón', '100 g'], ['Pesto', null]]);
});

it('accepts a week 2 dish without ingredients', function () {
    expect(Dish::named('Sobras del sábado')->ingredients())->toBe([]);
});

it('cannot be a week 1 dish without description', function () {
    Dish::described('   ');
})->throws(InvalidDish::class, 'Un plato no puede estar vacío: debe tener un nombre o una descripción.');

it('cannot be a week 2 dish without name, even with ingredients', function () {
    // Los ingredientes solos no bastan: sin nombre no se sabe qué plato es.
    Dish::named('', [DishIngredient::of('Pasta', '80 g')]);
})->throws(InvalidDish::class, 'Un plato no puede estar vacío: debe tener un nombre o una descripción.');

it('requires a name for each ingredient', function () {
    DishIngredient::of('  ', '80 g');
})->throws(InvalidDish::class, 'Un ingrediente de un plato debe tener nombre.');

it('treats a blank quantity as no quantity', function () {
    expect(DishIngredient::of('Sal', '  ')->quantity())->toBeNull();
});

it('compares ingredients by value, ignoring the case of the name', function () {
    expect(DishIngredient::of('Pasta', '80 g')->equals(DishIngredient::of('pasta', '80 g')))->toBeTrue()
        ->and(DishIngredient::of('Pasta', '80 g')->equals(DishIngredient::of('Pasta', '100 g')))->toBeFalse();
});

it('is compared by value', function () {
    $pasta = fn () => Dish::named('Pasta con salmón', [DishIngredient::of('Pasta', '80 g')]);

    expect($pasta()->equals($pasta()))->toBeTrue()
        ->and($pasta()->equals(Dish::named('Pasta con salmón')))->toBeFalse()
        ->and(Dish::described('Pasta con salmón')->equals(Dish::named('Pasta con salmón')))->toBeFalse();
});
