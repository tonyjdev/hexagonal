<?php

/*
| Tests de Weekday: numeración ISO (1 = lunes) y el sábado como día libre.
*/

use MealPlanning\Domain\Weekday;

it('uses the ISO-8601 numbering, so it can be read from a PHP date', function () {
    // 2026-11-01 fue domingo: format('N') devuelve 7 = Weekday::Sunday.
    // Así lo usará el calendario del nivel 4.
    $sunday = new DateTimeImmutable('2026-11-01');

    expect(Weekday::from((int) $sunday->format('N')))->toBe(Weekday::Sunday)
        ->and(Weekday::Monday->value)->toBe(1);
});

it('starts the week on Monday and has a Spanish label for every day', function () {
    expect(array_map(fn (Weekday $day) => $day->label(), Weekday::cases()))
        ->toBe(['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo']);
});

it('has Saturday as the only free day', function () {
    $freeDays = array_filter(Weekday::cases(), fn (Weekday $day) => $day->isFreeDay());

    expect(array_values($freeDays))->toBe([Weekday::Saturday]);
});
