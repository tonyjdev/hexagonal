<?php

declare(strict_types=1);

namespace MealPlanning\Domain;

use DateTimeImmutable;

/**
 * ScheduledDay: un día del calendario de un plan, tal como lo calcula
 * MealCalendar ("el miércoles 11 de noviembre toca la semana 2, y su menú
 * es este"; "el sábado 7 es libre").
 *
 * CONCEPTO: value object como resultado de una consulta del dominio
 * MealCalendar no devuelve un array suelto ['week' => 2, 'menu' => ...] ni
 * varios valores por separado, sino un objeto con nombre del lenguaje
 * ubicuo. Así:
 *   - el resultado se lee en el idioma del negocio ($day->isFreeDay());
 *   - sus reglas internas (un día libre no tiene menú; un día con menú lo
 *     tiene en su propio día de la semana) se cumplen siempre;
 *   - PHPStan conoce su forma exacta, cosa que no pasa con un array.
 * Es un VALOR, no una entidad: no tiene identidad ni ciclo de vida. Dos
 * ScheduledDay con la misma fecha, semana y menú son intercambiables.
 *
 * CONCEPTO: dos constructores con nombre para los dos casos
 * Un día programado tiene menú (withMenu()) o es el día libre (freeDay()).
 * Con dos constructores en vez de un `?MenuDay` en un constructor público,
 * no se puede construir un "día libre con menú" ni un "martes sin menú".
 *
 * CAPA: Dominio (MealPlanning\Domain).
 */
final readonly class ScheduledDay
{
    /**
     * Privado: solo se crea con withMenu() o freeDay().
     */
    private function __construct(
        private DateTimeImmutable $date,
        private int $weekNumber,
        private Weekday $weekday,
        private ?MenuDay $menu,
    ) {}

    /**
     * Un día con menú. El día de la semana es el del propio MenuDay.
     */
    public static function withMenu(DateTimeImmutable $date, int $weekNumber, MenuDay $menu): self
    {
        return new self($date, $weekNumber, $menu->weekday(), $menu);
    }

    /**
     * El día libre (sábado), que no tiene menú en ninguna semana.
     *
     * Su semana se conserva igualmente: "el sábado de la semana 2" es útil
     * para mostrarlo y para aprovechar los sobrantes de esa semana.
     */
    public static function freeDay(DateTimeImmutable $date, int $weekNumber): self
    {
        $weekday = Weekday::fromDate($date);

        if (! $weekday->isFreeDay()) {
            throw InvalidScheduledDay::notAFreeDay($weekday);
        }

        return new self($date, $weekNumber, $weekday, null);
    }

    /**
     * La fecha consultada, tal como llegó (con su hora y su zona horaria).
     */
    public function date(): DateTimeImmutable
    {
        return $this->date;
    }

    /**
     * La semana del plan que toca ese día: 1 o 2.
     */
    public function weekNumber(): int
    {
        return $this->weekNumber;
    }

    public function weekday(): Weekday
    {
        return $this->weekday;
    }

    /**
     * El menú del día (comida y cena), o null si es el día libre.
     */
    public function menu(): ?MenuDay
    {
        return $this->menu;
    }

    public function isFreeDay(): bool
    {
        return $this->menu === null;
    }
}
