<?php

/*
| Tests de los dos adaptadores del puerto Clock.
|
| Los dos cumplen el contrato del puerto: today() es una fecha a medianoche.
| FrozenClock, además, es determinista (siempre el mismo día); SystemClock
| calcula "hoy" en la zona horaria que recibe, no en la de PHP.
*/

use Shared\Application\Clock;
use Shared\Infrastructure\FrozenClock;
use Shared\Infrastructure\SystemClock;

it('freezes today on a fixed date, at midnight', function () {
    $clock = new FrozenClock(new DateTimeImmutable('2026-11-11 18:45:10'));

    // La hora se descarta: el puerto habla de fechas del calendario.
    expect($clock)->toBeInstanceOf(Clock::class)
        ->and($clock->today()->format('Y-m-d H:i:s'))->toBe('2026-11-11 00:00:00');
});

it('keeps returning the same day however many times it is asked', function () {
    $clock = FrozenClock::on('2026-11-11');

    expect($clock->today())->toEqual($clock->today())
        ->and($clock->today()->format('Y-m-d'))->toBe('2026-11-11');
});

it('keeps the time zone of the frozen date', function () {
    $clock = new FrozenClock(new DateTimeImmutable('2026-11-11 00:30', new DateTimeZone('Europe/Madrid')));

    expect($clock->today()->getTimezone()->getName())->toBe('Europe/Madrid')
        ->and($clock->today()->format('Y-m-d'))->toBe('2026-11-11');
});

it('tells today in the time zone it is given, at midnight', function () {
    $madrid = new DateTimeZone('Europe/Madrid');

    $today = (new SystemClock($madrid))->today();

    // Se compara con la fecha actual EN MADRID. (En teoría, si el test se
    // ejecutara justo al dar la medianoche, las dos líneas podrían caer en
    // días distintos; es el precio de probar el reloj real, y la razón de
    // que el resto de tests usen FrozenClock.)
    expect($today->getTimezone()->getName())->toBe('Europe/Madrid')
        ->and($today->format('H:i:s'))->toBe('00:00:00')
        ->and($today->format('Y-m-d'))->toBe((new DateTimeImmutable('now', $madrid))->format('Y-m-d'));
});

it('does not depend on the default time zone of PHP', function () {
    // Laravel configura UTC por defecto. Aunque PHP use otra zona, el reloj
    // calcula "hoy" en la suya.
    $original = date_default_timezone_get();
    date_default_timezone_set('Pacific/Kiritimati'); // UTC+14

    try {
        $today = (new SystemClock(new DateTimeZone('UTC')))->today();
    } finally {
        date_default_timezone_set($original);
    }

    expect($today->getTimezone()->getName())->toBe('UTC')
        ->and($today->format('Y-m-d'))->toBe((new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d'));
});
