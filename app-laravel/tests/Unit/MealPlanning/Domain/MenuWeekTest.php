<?php

/*
| Tests de MenuWeek: 6 días con menú (de lunes a viernes y el domingo), el
| sábado libre, sin días repetidos (regla 2), y los dos formatos de origen
| (menús 1-6 de la nutricionista y días 8-14 del usuario) traducidos al mismo
| modelo de días de la semana.
*/

use MealPlanning\Domain\Dish;
use MealPlanning\Domain\InvalidMenuDay;
use MealPlanning\Domain\InvalidMenuWeek;
use MealPlanning\Domain\MenuDay;
use MealPlanning\Domain\MenuWeek;
use MealPlanning\Domain\Weekday;
use MealPlanning\Domain\WeekSource;

/**
 * Día de menú de ejemplo con platos ficticios que dicen su día, para poder
 * comprobar después dónde ha acabado cada uno.
 */
function menuDay(Weekday $weekday): MenuDay
{
    return MenuDay::of(
        $weekday,
        Dish::described("Comida del {$weekday->label()}"),
        Dish::described("Cena del {$weekday->label()}"),
    );
}

/**
 * Los 6 días con menú de una semana correcta.
 *
 * @return list<MenuDay>
 */
function sixMenuDays(): array
{
    return array_map(menuDay(...), [
        Weekday::Monday, Weekday::Tuesday, Weekday::Wednesday,
        Weekday::Thursday, Weekday::Friday, Weekday::Sunday,
    ]);
}

/**
 * @param  list<MenuDay>  $days
 * @return list<string>
 */
function weekdayLabels(array $days): array
{
    return array_map(fn (MenuDay $day) => $day->weekday()->label(), $days);
}

it('has six menu days, from Monday to Friday and Sunday', function () {
    $week = MenuWeek::create(1, WeekSource::Nutritionist, sixMenuDays());

    expect($week->number())->toBe(1)
        ->and($week->source())->toBe(WeekSource::Nutritionist)
        ->and(weekdayLabels($week->days()))
        ->toBe(['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'domingo']);
});

it('keeps the days from Monday to Sunday whatever the order they arrive in', function () {
    $week = MenuWeek::create(2, WeekSource::Self, array_reverse(sixMenuDays()));

    expect(weekdayLabels($week->days()))
        ->toBe(['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'domingo']);
});

it('finds the menu of a weekday, and none on the free day', function () {
    $week = MenuWeek::create(1, WeekSource::Nutritionist, sixMenuDays());

    expect($week->day(Weekday::Wednesday)?->lunch()->description())->toBe('Comida del miércoles')
        ->and($week->day(Weekday::Saturday))->toBeNull();
});

it('is week 1 or week 2', function () {
    MenuWeek::create(3, WeekSource::Self, sixMenuDays());
})->throws(InvalidMenuWeek::class, 'Un plan tiene la semana 1 y la semana 2: la semana 3 no existe.');

it('rejects a week with fewer than six menu days', function () {
    MenuWeek::create(1, WeekSource::Nutritionist, array_slice(sixMenuDays(), 0, 5));
})->throws(InvalidMenuWeek::class, 'Una semana debe tener 6 días con menú (de lunes a viernes y el domingo), y esta tiene 5.');

it('rejects a week with more than six menu days', function () {
    // Con 7 días, uno está repetido a la fuerza: el único día que falta
    // en una semana correcta es el sábado, y ese MenuDay no se puede crear.
    // Por eso "más de 6" siempre se rechaza como día repetido.
    MenuWeek::create(1, WeekSource::Nutritionist, [...sixMenuDays(), menuDay(Weekday::Monday)]);
})->throws(InvalidMenuWeek::class, 'El lunes aparece más de una vez en la semana.');

it('rejects a repeated day even if there are six days', function () {
    // Dos lunes y ningún martes: hay 6 días, pero no son los correctos.
    $days = sixMenuDays();
    $days[1] = menuDay(Weekday::Monday);

    MenuWeek::create(1, WeekSource::Nutritionist, $days);
})->throws(InvalidMenuWeek::class, 'El lunes aparece más de una vez en la semana.');

it('rejects a menu on Saturday', function () {
    // No hace falta comprobarlo en la semana: ni siquiera se puede crear
    // el MenuDay del sábado.
    MenuWeek::create(1, WeekSource::Nutritionist, [...array_slice(sixMenuDays(), 0, 5), menuDay(Weekday::Saturday)]);
})->throws(InvalidMenuDay::class, 'El sábado es día libre: no puede tener menú.');

it('cannot be changed from outside', function () {
    // CONCEPTO: colección inmutable. days() devuelve una copia del array:
    // quitar o añadir días en ella no toca la semana.
    $week = MenuWeek::create(1, WeekSource::Nutritionist, sixMenuDays());

    $days = $week->days();
    array_pop($days);
    $days[] = menuDay(Weekday::Monday);

    expect($week->days())->toHaveCount(6)
        ->and($week->day(Weekday::Sunday))->not->toBeNull();
});

// --- Semana 1: menús 1 a 6 de la nutricionista -------------------------------

/**
 * Los menús 1-6 tal como vienen en el documento de la nutricionista.
 *
 * @return array<int, array{lunch?: ?Dish, dinner?: ?Dish}>
 */
function nutritionistMenus(): array
{
    $menus = [];

    foreach (range(1, 6) as $menu) {
        $menus[$menu] = [
            'lunch' => Dish::described("Comida del menú {$menu}"),
            'dinner' => Dish::described("Cena del menú {$menu}"),
        ];
    }

    return $menus;
}

it('builds week 1 from the nutritionist menus 1 to 6, skipping Saturday', function () {
    $week = MenuWeek::fromNutritionistMenus(nutritionistMenus());

    expect($week->number())->toBe(1)
        ->and($week->source())->toBe(WeekSource::Nutritionist)
        ->and($week->day(Weekday::Monday)?->lunch()->description())->toBe('Comida del menú 1')
        ->and($week->day(Weekday::Friday)?->dinner()->description())->toBe('Cena del menú 5')
        // La regla clave: el menú 6 no es el sábado, sino el domingo.
        ->and($week->day(Weekday::Sunday)?->lunch()->description())->toBe('Comida del menú 6')
        ->and($week->day(Weekday::Saturday))->toBeNull();
});

it('rejects a nutritionist menu outside 1 to 6', function () {
    // Ojo: no vale [...$menus, 7 => ...]: el spread de PHP renumera las
    // claves enteras (1-6 pasarían a ser 0-5). Se asigna la clave a mano.
    $menus = nutritionistMenus();
    $menus[7] = $menus[1];

    MenuWeek::fromNutritionistMenus($menus);
})->throws(InvalidMenuWeek::class, 'La semana 1 numera los menús del 1 al 6: el menú 7 no existe.');

it('rejects week 1 with a missing menu', function () {
    $menus = nutritionistMenus();
    unset($menus[4]);

    MenuWeek::fromNutritionistMenus($menus);
})->throws(InvalidMenuWeek::class, 'y esta tiene 5.');

it('rejects a nutritionist menu without dinner', function () {
    $menus = nutritionistMenus();
    unset($menus[6]['dinner']);

    MenuWeek::fromNutritionistMenus($menus);
})->throws(InvalidMenuDay::class, 'Al menú del domingo le falta la cena.');

// --- Semana 2: días 8 a 14 del usuario ---------------------------------------

/**
 * Los días 8-14 tal como los escribe el usuario, con el 13 (sábado) vacío.
 *
 * @return array<int, array{lunch?: ?Dish, dinner?: ?Dish}|null>
 */
function selfDays(): array
{
    $days = [];

    foreach (range(8, 14) as $day) {
        $days[$day] = $day === 13 ? null : [
            'lunch' => Dish::named("Comida del día {$day}"),
            'dinner' => Dish::named("Cena del día {$day}"),
        ];
    }

    return $days;
}

it('builds week 2 from the days 8 to 14, with day 13 free', function () {
    $week = MenuWeek::fromSelfDays(selfDays());

    expect($week->number())->toBe(2)
        ->and($week->source())->toBe(WeekSource::Self)
        ->and($week->day(Weekday::Monday)?->lunch()->name())->toBe('Comida del día 8')
        ->and($week->day(Weekday::Friday)?->lunch()->name())->toBe('Comida del día 12')
        ->and($week->day(Weekday::Saturday))->toBeNull()
        ->and($week->day(Weekday::Sunday)?->dinner()->name())->toBe('Cena del día 14');
});

it('also accepts week 2 without the free day 13', function () {
    $days = selfDays();
    unset($days[13]);

    expect(MenuWeek::fromSelfDays($days)->days())->toHaveCount(6);
});

it('rejects a menu on day 13, which is Saturday', function () {
    $days = selfDays();
    $days[13] = ['lunch' => Dish::named('Pizza'), 'dinner' => Dish::named('Sobras')];

    MenuWeek::fromSelfDays($days);
})->throws(InvalidMenuDay::class, 'El sábado es día libre: no puede tener menú.');

it('rejects a day outside 8 to 14', function () {
    $days = selfDays();
    $days[15] = null;

    MenuWeek::fromSelfDays($days);
})->throws(InvalidMenuWeek::class, 'La semana 2 numera los días del 8 al 14: el día 15 no existe.');

it('rejects week 2 with an empty day that is not Saturday', function () {
    $days = selfDays();
    $days[10] = null;

    MenuWeek::fromSelfDays($days);
})->throws(InvalidMenuWeek::class, 'y esta tiene 5.');
