<?php

declare(strict_types=1);

namespace Shared\Infrastructure;

use DateTimeImmutable;
use Shared\Application\Clock;

/**
 * FrozenClock: un reloj parado en una fecha fija.
 *
 * CONCEPTO: test double (un "fake" del reloj)
 * Con FrozenClock, el test decide qué día es hoy: "si hoy fuera el miércoles
 * 11 de noviembre, ¿qué me tocaría?". El resultado es el mismo se ejecute el
 * día que se ejecute, y se pueden probar casos que con el reloj real solo
 * pasan una vez a la semana (un sábado) o nunca (un día antes del primer
 * plan). En Laravel se suele hacer con Carbon::setTestNow() o
 * $this->travelTo(), que cambian un reloj GLOBAL; aquí no hay nada global:
 * cada caso de uso recibe su reloj por el constructor.
 *
 * Está en Infrastructure, como InMemoryNutritionPlanRepository, porque es un
 * adaptador más del puerto, aunque hoy solo lo usen los tests. También
 * podría servir para una demo ("simula que es el día X").
 *
 * CAPA: Infraestructura compartida (Shared\Infrastructure).
 */
final readonly class FrozenClock implements Clock
{
    private DateTimeImmutable $today;

    /**
     * Se queda solo con la fecha (a medianoche, en su misma zona horaria):
     * el contrato del puerto dice que today() no lleva hora.
     */
    public function __construct(DateTimeImmutable $today)
    {
        $this->today = $today->setTime(0, 0);
    }

    /**
     * Atajo para los tests: FrozenClock::on('2026-11-11').
     */
    public static function on(string $date): self
    {
        return new self(new DateTimeImmutable($date));
    }

    public function today(): DateTimeImmutable
    {
        return $this->today;
    }
}
