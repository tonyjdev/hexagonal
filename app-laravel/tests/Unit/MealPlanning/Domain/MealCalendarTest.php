<?php

/*
| Tests de MealCalendar: "con este plan, ¿qué me toca el día X?".
| Las semanas se alternan desde el lunes de inicio, el sábado es libre y
| antes del inicio no hay menú. La hora, la zona horaria y el cambio de
| horario de verano no deben mover el resultado.
|
| Las funciones auxiliares llevan el prefijo calendar (en Pest son globales).
*/

use MealPlanning\Domain\Dish;
use MealPlanning\Domain\ExchangeTable;
use MealPlanning\Domain\InvalidScheduledDay;
use MealPlanning\Domain\MealCalendar;
use MealPlanning\Domain\MenuWeek;
use MealPlanning\Domain\NutritionPlan;
use MealPlanning\Domain\NutritionPlanId;
use MealPlanning\Domain\PlanNumber;
use MealPlanning\Domain\ScheduledDay;
use MealPlanning\Domain\Weekday;

/**
 * Un plan 27 ficticio que empieza el lunes 2026-11-02. Los platos dicen de
 * dónde vienen ("menú 3", "día 10"), para comprobar qué semana ha tocado.
 */
function calendarPlan(?DateTimeImmutable $startsOn = null): NutritionPlan
{
    $menus = [];
    foreach (range(1, 6) as $menu) {
        $menus[$menu] = ['lunch' => Dish::described("Comida del menú {$menu}"), 'dinner' => Dish::described("Cena del menú {$menu}")];
    }

    $days = [];
    foreach (range(8, 14) as $day) {
        $days[$day] = $day === 13 ? null : ['lunch' => Dish::named("Comida del día {$day}"), 'dinner' => Dish::named("Cena del día {$day}")];
    }

    return NutritionPlan::create(
        NutritionPlanId::generate(),
        PlanNumber::of(27),
        $startsOn ?? new DateTimeImmutable('2026-11-02'),
        [],
        ExchangeTable::of([]),
        [MenuWeek::fromNutritionistMenus($menus), MenuWeek::fromSelfDays($days)],
    );
}

/**
 * Qué comida toca ese día, o 'libre': resume un ScheduledDay en una línea.
 */
function calendarLunch(ScheduledDay $day): string
{
    $lunch = $day->menu()?->lunch();

    return $lunch === null ? 'libre' : ($lunch->name() ?? $lunch->description() ?? '');
}

function calendarDayOn(string $date, ?NutritionPlan $plan = null): ScheduledDay
{
    return (new MealCalendar)->dayOn($plan ?? calendarPlan(), new DateTimeImmutable($date));
}

// --- Alternancia de semanas ---------------------------------------------------

it('starts with week 1 on the day the plan starts', function () {
    $day = calendarDayOn('2026-11-02');

    expect($day->weekNumber())->toBe(1)
        ->and($day->weekday())->toBe(Weekday::Monday)
        ->and(calendarLunch($day))->toBe('Comida del menú 1');
});

it('gives menu 6 on the first Sunday', function () {
    expect(calendarLunch(calendarDayOn('2026-11-08')))->toBe('Comida del menú 6');
});

it('switches to week 2 on the second Monday', function () {
    $day = calendarDayOn('2026-11-09');

    expect($day->weekNumber())->toBe(2)
        ->and(calendarLunch($day))->toBe('Comida del día 8');
});

it('goes back to week 1 on the third Monday', function () {
    $day = calendarDayOn('2026-11-16');

    expect($day->weekNumber())->toBe(1)
        ->and(calendarLunch($day))->toBe('Comida del menú 1');
});

it('keeps alternating months later', function () {
    // 107 días después del inicio: 15 semanas completas (impar) → semana 2,
    // y el miércoles de la semana 2 es el día 10.
    $day = calendarDayOn('2027-02-17');

    expect($day->weekNumber())->toBe(2)
        ->and(calendarLunch($day))->toBe('Comida del día 10');
});

// --- Sábado libre -------------------------------------------------------------

it('has Saturday free in both weeks', function () {
    $week1Saturday = calendarDayOn('2026-11-07');
    $week2Saturday = calendarDayOn('2026-11-14');

    expect($week1Saturday->isFreeDay())->toBeTrue()
        ->and($week1Saturday->menu())->toBeNull()
        ->and($week1Saturday->weekNumber())->toBe(1)
        ->and($week2Saturday->isFreeDay())->toBeTrue()
        ->and($week2Saturday->weekNumber())->toBe(2)
        ->and($week2Saturday->weekday())->toBe(Weekday::Saturday);
});

it('only builds a free day on the free day', function () {
    ScheduledDay::freeDay(new DateTimeImmutable('2026-11-04'), 1);
})->throws(InvalidScheduledDay::class, 'El miércoles no es día libre: tiene menú.');

// --- Antes del inicio ---------------------------------------------------------

it('has no menu before the plan starts', function () {
    calendarDayOn('2026-11-01');
})->throws(InvalidScheduledDay::class, 'El plan 27 empieza el 02/11/2026: el 01/11/2026 no tiene menú en este plan.');

// --- Horas, zonas horarias y horario de verano --------------------------------

it('ignores the time of day', function () {
    // Un minuto antes de medianoche sigue siendo el mismo día del calendario.
    expect(calendarLunch(calendarDayOn('2026-11-08 23:59')))->toBe('Comida del menú 6')
        ->and(calendarLunch(calendarDayOn('2026-11-09 00:00')))->toBe('Comida del día 8');
});

it('uses the calendar date of whoever asks, in their time zone', function () {
    // Las 00:30 del lunes 9 en Madrid son las 23:30 del domingo 8 en UTC.
    // Para quien pregunta desde Madrid, ya es lunes: semana 2, día 8.
    $madrid = new DateTimeImmutable('2026-11-09 00:30', new DateTimeZone('Europe/Madrid'));

    $day = (new MealCalendar)->dayOn(calendarPlan(), $madrid);

    expect($day->weekNumber())->toBe(2)
        ->and(calendarLunch($day))->toBe('Comida del día 8');
});

it('is not fooled by the daylight saving time change', function () {
    // El domingo 2027-03-28 se adelanta la hora en Madrid: esa semana tiene
    // una hora menos. Contar "segundos / 86400" daría 146,96 días hasta el
    // lunes 29 y lo dejaría en domingo; por fechas del calendario son 147.
    $madrid = new DateTimeZone('Europe/Madrid');
    $plan = calendarPlan(new DateTimeImmutable('2026-11-02', $madrid));

    $sunday = (new MealCalendar)->dayOn($plan, new DateTimeImmutable('2027-03-28 12:00', $madrid));
    $monday = (new MealCalendar)->dayOn($plan, new DateTimeImmutable('2027-03-29 00:00', $madrid));

    expect($sunday->weekNumber())->toBe(1)
        ->and(calendarLunch($sunday))->toBe('Comida del menú 6')
        ->and($monday->weekNumber())->toBe(2)
        ->and(calendarLunch($monday))->toBe('Comida del día 8');
});

it('keeps the date it was asked for', function () {
    $date = new DateTimeImmutable('2026-11-11 14:00');

    expect((new MealCalendar)->dayOn(calendarPlan(), $date)->date())->toBe($date);
});
