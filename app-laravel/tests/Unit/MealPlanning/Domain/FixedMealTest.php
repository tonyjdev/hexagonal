<?php

/*
| Tests de las pautas fijas: FixedMeal (desayuno, media mañana y media tarde,
| iguales todos los días) y sus grupos "elige N de las siguientes"
| (OptionGroup con MealOption).
*/

use MealPlanning\Domain\FixedMeal;
use MealPlanning\Domain\InvalidFixedMeal;
use MealPlanning\Domain\InvalidOptionGroup;
use MealPlanning\Domain\MealOption;
use MealPlanning\Domain\MealType;
use MealPlanning\Domain\OptionGroup;

/**
 * @return list<MealOption>
 */
function midMorningOptions(): array
{
    return [
        MealOption::of('1 pieza de fruta'),
        MealOption::of('Yogur natural desnatado'),
        MealOption::of('20 g de nueces'),
    ];
}

// --- MealType::isFixed() ------------------------------------------------------

it('knows which meals are fixed every day', function () {
    $fixed = array_filter(MealType::cases(), fn (MealType $meal) => $meal->isFixed());

    expect(array_values($fixed))->toBe([MealType::Breakfast, MealType::MidMorning, MealType::AfternoonSnack]);
});

// --- OptionGroup y MealOption -------------------------------------------------

it('is a group "choose N of the following"', function () {
    $group = OptionGroup::choose(2, midMorningOptions());

    expect($group->count())->toBe(2)
        ->and(array_map(fn (MealOption $option) => $option->text(), $group->options()))
        ->toBe(['1 pieza de fruta', 'Yogur natural desnatado', '20 g de nueces']);
});

it('allows choosing all the options', function () {
    // "Elige 2 de 2": raro pero válido, equivale a tomar las dos.
    expect(OptionGroup::choose(2, array_slice(midMorningOptions(), 0, 2))->count())->toBe(2);
});

it('rejects choosing zero options', function () {
    OptionGroup::choose(0, midMorningOptions());
})->throws(InvalidOptionGroup::class, 'En un grupo de opciones hay que elegir al menos una, no 0.');

it('rejects choosing more options than there are', function () {
    OptionGroup::choose(4, midMorningOptions());
})->throws(InvalidOptionGroup::class, 'No se pueden elegir 4 opciones de un grupo que solo tiene 3.');

it('rejects a group without options', function () {
    // Sin opciones, cualquier N ≥ 1 es "más de las que hay".
    OptionGroup::choose(1, []);
})->throws(InvalidOptionGroup::class, 'No se pueden elegir 1 opciones de un grupo que solo tiene 0.');

it('rejects a repeated option, ignoring case and spaces', function () {
    OptionGroup::choose(1, [...midMorningOptions(), MealOption::of('  yogur  natural DESNATADO ')]);
})->throws(InvalidOptionGroup::class, 'La opción "yogur natural DESNATADO" está repetida en el grupo.');

it('rejects an empty option', function () {
    MealOption::of('   ');
})->throws(InvalidOptionGroup::class, 'Una opción de un grupo no puede estar vacía.');

it('compares option groups by value', function () {
    expect(OptionGroup::choose(2, midMorningOptions())->equals(OptionGroup::choose(2, midMorningOptions())))->toBeTrue()
        ->and(OptionGroup::choose(2, midMorningOptions())->equals(OptionGroup::choose(1, midMorningOptions())))->toBeFalse()
        ->and(OptionGroup::choose(2, midMorningOptions())->equals(OptionGroup::choose(2, array_reverse(midMorningOptions()))))->toBeFalse()
        ->and(MealOption::of('Yogur natural')->equals(MealOption::of('yogur natural')))->toBeTrue();
});

// --- FixedMeal ----------------------------------------------------------------

it('is a breakfast with a fixed part and a group of options', function () {
    $breakfast = FixedMeal::of(MealType::Breakfast, ' Café con leche desnatada ', [
        OptionGroup::choose(1, [
            MealOption::of('Tostada de pan integral (40 g) con tomate'),
            MealOption::of('30 g de copos de avena'),
        ]),
    ]);

    expect($breakfast->mealType())->toBe(MealType::Breakfast)
        ->and($breakfast->description())->toBe('Café con leche desnatada')
        ->and($breakfast->optionGroups())->toHaveCount(1)
        ->and($breakfast->optionGroups()[0]->count())->toBe(1);
});

it('can be only a group of options', function () {
    $midMorning = FixedMeal::of(MealType::MidMorning, null, [OptionGroup::choose(2, midMorningOptions())]);

    expect($midMorning->description())->toBeNull();
});

it('can be only a fixed part, keeping its line breaks', function () {
    $snack = FixedMeal::of(MealType::AfternoonSnack, "Infusión\n1 pieza de fruta");

    expect($snack->description())->toBe("Infusión\n1 pieza de fruta")
        ->and($snack->optionGroups())->toBe([]);
});

it('cannot be lunch or dinner, which change every day', function (MealType $meal, string $message) {
    expect(fn () => FixedMeal::of($meal, 'Lentejas con pisto'))->toThrow(InvalidFixedMeal::class, $message);
})->with([
    [MealType::Lunch, 'La comida no es una pauta fija: cambia cada día y va en el menú.'],
    [MealType::Dinner, 'La cena no es una pauta fija: cambia cada día y va en el menú.'],
]);

it('cannot be empty', function () {
    // Un texto en blanco cuenta como "sin parte fija".
    FixedMeal::of(MealType::MidMorning, '   ');
})->throws(InvalidFixedMeal::class, 'La pauta de media mañana no puede estar vacía: necesita una parte fija o algún grupo de opciones.');

it('is immutable and compared by value', function () {
    $midMorning = fn () => FixedMeal::of(MealType::MidMorning, null, [OptionGroup::choose(2, midMorningOptions())]);

    // Quitar un grupo de la copia devuelta no cambia la pauta.
    $meal = $midMorning();
    $copy = $meal->optionGroups();
    array_pop($copy);

    expect($meal->optionGroups())->toHaveCount(1)
        ->and($midMorning()->equals($midMorning()))->toBeTrue()
        ->and($midMorning()->equals(FixedMeal::of(MealType::AfternoonSnack, null, [OptionGroup::choose(2, midMorningOptions())])))->toBeFalse()
        ->and($midMorning()->equals(FixedMeal::of(MealType::MidMorning, 'Café', [OptionGroup::choose(2, midMorningOptions())])))->toBeFalse();
});
