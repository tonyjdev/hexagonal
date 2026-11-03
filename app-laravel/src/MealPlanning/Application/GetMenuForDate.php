<?php

declare(strict_types=1);

namespace MealPlanning\Application;

use DateTimeImmutable;
use MealPlanning\Domain\MealCalendar;
use MealPlanning\Domain\NutritionPlanRepository;
use Shared\Application\Clock;

/**
 * GetMenuForDate: caso de uso "¿qué me toca comer hoy (o el día X)?".
 *
 * Es la pregunta clave de la aplicación (docs/DOMINIO.md) y el primer caso de
 * uso del contexto MealPlanning.
 *
 *     fecha (o hoy, según el Clock)
 *        │
 *        ├─► NutritionPlanRepository::inForceOn(fecha) ── ¿ninguno? → NoPlanInForce
 *        │
 *        ├─► MealCalendar::dayOn(plan, fecha) → ScheduledDay
 *        │
 *        └─► ScheduledDayView (primitivos)
 *
 * CONCEPTO: caso de uso de consulta que ORQUESTA
 * No contiene ninguna regla del negocio: la vigencia la sabe el repositorio
 * (su contrato, ADR 0011) y el calendario, MealCalendar (HEXM-0017). El caso
 * de uso solo coordina: elige la fecha, pide el plan, pide el día y traduce
 * el resultado a una vista. Si mañana cambia la regla del sábado libre, este
 * fichero no se entera. Es una CONSULTA (CQS): no cambia nada y se puede
 * repetir sin efectos.
 *
 * CONCEPTO: inyección de dependencias de un puerto, un servicio de dominio y
 * el reloj
 * Las tres le llegan por el constructor. Hoy los tests le pasan el
 * repositorio en memoria y un FrozenClock; en el nivel 5, el service
 * provider le pasará Eloquent y SystemClock, sin cambiar ni una línea de
 * aquí.
 *
 * DECISIÓN: la fecha llega como DateTimeImmutable opcional (HEXM-0019)
 * GetRecipe recibe el id como texto, porque un id no es un tipo de PHP. Una
 * fecha sí lo es, nativo y permitido en el núcleo, así que no hace falta
 * pasarla como '2026-11-11' y validarla aquí: un adaptador (HTTP, Artisan) la
 * convierte y ya. Sin fecha (null), se usa "hoy" según el Clock: así el
 * caso de uso NO lee nunca el reloj del sistema por su cuenta.
 *
 * Contrato:
 *   - devuelve un ScheduledDayView, nunca el plan ni el ScheduledDay;
 *   - sin plan vigente en esa fecha, lanza NoPlanInForce (un NotFound);
 *   - con plan vigente, MealCalendar nunca falla: inForceOn() solo devuelve
 *     planes que ya han empezado en esa fecha.
 *
 * CAPA: Aplicación (MealPlanning\Application).
 */
final readonly class GetMenuForDate
{
    public function __construct(
        private NutritionPlanRepository $plans,
        private MealCalendar $calendar,
        private Clock $clock,
    ) {}

    public function __invoke(?DateTimeImmutable $date = null): ScheduledDayView
    {
        // Con fecha explícita, el reloj ni se consulta.
        $date ??= $this->clock->today();

        $plan = $this->plans->inForceOn($date) ?? throw NoPlanInForce::on($date);

        return ScheduledDayView::fromScheduledDay($plan, $this->calendar->dayOn($plan, $date));
    }
}
