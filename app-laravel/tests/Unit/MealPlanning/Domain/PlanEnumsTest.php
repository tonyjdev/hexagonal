<?php

/*
| Tests de los enums de MealPlanning: valor guardado en inglés y etiqueta en
| español. Mismo enfoque que UnitTest en el recetario.
*/

use MealPlanning\Domain\ExchangeCategory;
use MealPlanning\Domain\MealType;
use MealPlanning\Domain\WeekSource;

it('has a Spanish label for every case', function (MealType|WeekSource|ExchangeCategory $case) {
    // Dataset con todos los casos de los tres enums: un caso nuevo sin
    // etiqueta rompe este test (y antes, el análisis de PHPStan).
    expect($case->label())->toBeString()->not->toBeEmpty();
})->with([...MealType::cases(), ...WeekSource::cases(), ...ExchangeCategory::cases()]);

it('lists the meals in the order they are eaten', function () {
    expect(array_map(fn (MealType $meal) => $meal->label(), MealType::cases()))
        ->toBe(['desayuno', 'media mañana', 'comida', 'media tarde', 'cena']);
});

it('uses the names of the glossary', function () {
    expect(WeekSource::Nutritionist->label())->toBe('nutricionista')
        ->and(WeekSource::Self->label())->toBe('elaboración propia')
        ->and(ExchangeCategory::Carbohydrates->label())->toBe('hidratos')
        ->and(ExchangeCategory::Fat->label())->toBe('grasas');
});

it('is recovered from its stored value', function () {
    expect(MealType::from('afternoon_snack'))->toBe(MealType::AfternoonSnack)
        ->and(WeekSource::from('self'))->toBe(WeekSource::Self)
        ->and(ExchangeCategory::tryFrom('grasas'))->toBeNull();
});
