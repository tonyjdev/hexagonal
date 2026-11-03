<?php

/*
| Tests de MenuDay: un día con menú tiene siempre comida y cena (regla 3) y
| nunca cae en sábado, el día libre (regla 2). Es un value object.
*/

use MealPlanning\Domain\Dish;
use MealPlanning\Domain\InvalidMenuDay;
use MealPlanning\Domain\MenuDay;
use MealPlanning\Domain\Weekday;

it('has a lunch and a dinner', function () {
    $day = MenuDay::of(
        Weekday::Tuesday,
        Dish::described('Lentejas (½ bote) con pisto'),
        Dish::described('Merluza a la plancha con ensalada'),
    );

    expect($day->weekday())->toBe(Weekday::Tuesday)
        ->and($day->lunch()->description())->toBe('Lentejas (½ bote) con pisto')
        ->and($day->dinner()->description())->toBe('Merluza a la plancha con ensalada');
});

it('cannot be missing the lunch', function () {
    // Un documento externo puede venir incompleto: error de negocio en
    // español, no un TypeError de PHP (por eso of() admite ?Dish).
    MenuDay::of(Weekday::Monday, null, Dish::described('Tortilla francesa'));
})->throws(InvalidMenuDay::class, 'Al menú del lunes le falta la comida.');

it('cannot be missing the dinner', function () {
    MenuDay::of(Weekday::Wednesday, Dish::described('Arroz con pollo'), null);
})->throws(InvalidMenuDay::class, 'Al menú del miércoles le falta la cena.');

it('cannot be on Saturday, the free day', function () {
    MenuDay::of(Weekday::Saturday, Dish::described('Pizza'), Dish::described('Sobras'));
})->throws(InvalidMenuDay::class, 'El sábado es día libre: no puede tener menú.');

it('is compared by value', function () {
    $tuesday = fn (string $dinner) => MenuDay::of(Weekday::Tuesday, Dish::named('Pasta con salmón'), Dish::named($dinner));

    expect($tuesday('Tortilla')->equals($tuesday('Tortilla')))->toBeTrue()
        ->and($tuesday('Tortilla')->equals($tuesday('Crema de calabaza')))->toBeFalse()
        ->and($tuesday('Tortilla')->equals(MenuDay::of(Weekday::Monday, Dish::named('Pasta con salmón'), Dish::named('Tortilla'))))->toBeFalse();
});
