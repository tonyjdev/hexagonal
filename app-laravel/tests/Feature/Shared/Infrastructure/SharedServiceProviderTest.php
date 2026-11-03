<?php

/*
| Tests del cableado de los puertos compartidos (SharedServiceProvider).
|
| Los tests unitarios de los casos de uso usan FrozenClock; estos comprueban
| que, en la aplicación real, el puerto Clock es el reloj de verdad en la
| zona horaria configurada (config/clock.php), no en la de Laravel (UTC).
*/

use Shared\Application\Clock;
use Shared\Infrastructure\SystemClock;

it('binds the clock port to the system clock', function () {
    expect(app(Clock::class))->toBeInstanceOf(SystemClock::class);
});

it('tells today in the configured time zone, by default Europe/Madrid', function () {
    expect(config('clock.timezone'))->toBe('Europe/Madrid')
        ->and(app(Clock::class)->today()->getTimezone()->getName())->toBe('Europe/Madrid')
        // app.timezone sigue siendo UTC: la zona del usuario no la cambia.
        ->and(config('app.timezone'))->toBe('UTC');
});

it('follows the configuration when the time zone changes', function () {
    // CONCEPTO: la zona horaria es una decisión de despliegue
    // Otra configuración, otro "hoy", sin tocar ni una línea de código.
    config(['clock.timezone' => 'Atlantic/Canary']);
    $this->app->forgetInstance(Clock::class);

    expect(app(Clock::class)->today()->getTimezone()->getName())->toBe('Atlantic/Canary');
});

it('refuses to start with a time zone that does not exist', function () {
    // Una errata en la configuración falla al resolver el reloj, en lugar de
    // calcular "hoy" en una zona equivocada.
    config(['clock.timezone' => 'Europe/Madird']);
    $this->app->forgetInstance(Clock::class);

    expect(fn () => app(Clock::class))->toThrow(Exception::class);
});
