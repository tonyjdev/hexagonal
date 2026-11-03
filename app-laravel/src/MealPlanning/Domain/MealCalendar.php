<?php

declare(strict_types=1);

namespace MealPlanning\Domain;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;

/**
 * MealCalendar: responde "con este plan, ¿qué me toca el día X?".
 *
 *     Plan 27, inicio el lunes 02/11/2026:
 *       02/11 … 08/11  → semana 1 (menús 1-6, sábado 07 libre)
 *       09/11 … 15/11  → semana 2 (días 8-14, sábado 14 libre)
 *       16/11 … 22/11  → semana 1 otra vez … y así, alternando
 *
 * CONCEPTO: servicio de dominio
 * Es lógica del NEGOCIO (las reglas del calendario están en DOMINIO.md, no
 * en Laravel), pero no es el estado de nada: no tiene identidad ni datos
 * propios. Recibe lo que necesita (un plan y una fecha) y devuelve un valor
 * (ScheduledDay). Por eso no tiene propiedades: es un servicio SIN ESTADO.
 *
 * DECISIÓN: servicio y no método de NutritionPlan (HEXM-0017)
 * El plan tiene todos los datos para el cálculo, y NutritionPlan::dayOn()
 * habría sido válido. Se prefiere un servicio porque:
 *   - el agregado se ocupa de su CONSISTENCIA (sus invariantes), y el
 *     calendario es otra responsabilidad: interpretar el plan en el tiempo;
 *   - el calendario puede crecer más allá de un plan (una semana que cruza
 *     el cambio de plan, nivel 5) sin engordar el agregado;
 *   - es el nombre que ya usa el negocio en el glosario ("calendario").
 * Ojo: un servicio de dominio NO es un "service" de Laravel con lógica de
 * aplicación. Vive en el dominio, solo usa el dominio y PHP nativo, y no
 * sabe nada de repositorios, relojes ni HTTP: el plan y la fecha le llegan
 * como argumentos. Buscar el plan vigente y saber qué día es "hoy" serán
 * cosas del caso de uso (HEXM-0019).
 *
 * Es una clase (y no funciones estáticas) para poder inyectarla en el caso de
 * uso, como cualquier otra dependencia.
 *
 * CAPA: Dominio (MealPlanning\Domain).
 */
final readonly class MealCalendar
{
    private const int DAYS_PER_WEEK = 7;

    /**
     * El día programado para una fecha: qué semana toca y su menú, o que es
     * el día libre.
     *
     * Las reglas, en el orden en que se aplican:
     *   1. Antes del inicio del plan no hay menú en ese plan.
     *   2. Las semanas se alternan desde el inicio: semanas completas
     *      transcurridas pares → semana 1; impares → semana 2.
     *   3. El sábado es libre (en las dos semanas).
     *   4. Cualquier otro día tiene el menú de su día de la semana.
     */
    public function dayOn(NutritionPlan $plan, DateTimeImmutable $date): ScheduledDay
    {
        $days = self::calendarDaysBetween($plan->startsOn(), $date);

        if ($days < 0) {
            throw InvalidScheduledDay::beforePlanStarts($date, $plan);
        }

        // intdiv: división entera de PHP (como (int) floor() para positivos).
        // Del día 0 al 6 → semana 0 (par) → la 1; del 7 al 13 → semana 1
        // (impar) → la 2; del 14 al 20 → semana 2 (par) → la 1...
        $weekNumber = intdiv($days, self::DAYS_PER_WEEK) % 2 === 0 ? 1 : 2;

        if (Weekday::fromDate($date)->isFreeDay()) {
            return ScheduledDay::freeDay($date, $weekNumber);
        }

        // MenuWeek garantiza que tiene menú en todos los días menos el
        // sábado, así que el null es imposible aquí. Si llegara a pasar, es
        // un error de programación (LogicException), no del usuario.
        $menu = $plan->week($weekNumber)->day(Weekday::fromDate($date))
            ?? throw new LogicException('Una semana de menú tiene menú todos los días menos el sábado.');

        return ScheduledDay::withMenu($date, $weekNumber, $menu);
    }

    /**
     * Días del calendario entre dos fechas (negativo si $to es anterior).
     *
     * CONCEPTO: fechas del calendario frente a instantes
     * "El 9 de noviembre" es una FECHA DEL CALENDARIO; "el 9 de noviembre a
     * las 00:30 en Madrid" es un INSTANTE, que en UTC aún es el día 8. Para
     * el calendario del plan solo importa la fecha tal como la ve quien
     * pregunta. Por eso:
     *   1. se toma el año-mes-día de cada fecha EN SU PROPIA zona horaria
     *      (format('Y-m-d')), olvidando la hora;
     *   2. se reconstruyen las dos en UTC, que no tiene cambios de hora;
     *   3. se cuentan los días entre ellas con diff().
     *
     * La alternativa ingenua, (segundos / 86400), falla con el horario de
     * verano: la semana del cambio de hora de marzo tiene una hora menos, son
     * 6,96 días, y al truncar sale 6 en vez de 7. Se comprueba en el test.
     */
    private static function calendarDaysBetween(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $utc = new DateTimeZone('UTC');
        $fromDate = new DateTimeImmutable($from->format('Y-m-d'), $utc);
        $toDate = new DateTimeImmutable($to->format('Y-m-d'), $utc);

        $interval = $fromDate->diff($toDate);

        // ->days es int|false en los tipos de PHP: false solo si el intervalo
        // no sale de diff(), que no es el caso. ->invert = 1 si $to < $from.
        $days = (int) $interval->days;

        return $interval->invert === 1 ? -$days : $days;
    }
}
