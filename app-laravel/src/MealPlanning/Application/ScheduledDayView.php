<?php

declare(strict_types=1);

namespace MealPlanning\Application;

use MealPlanning\Domain\NutritionPlan;
use MealPlanning\Domain\ScheduledDay;

/**
 * ScheduledDayView: lo que me toca comer un día, listo para mostrar. Es lo
 * que devuelve GetMenuForDate.
 *
 *     2026-11-11 · plan 27 · semana 2 · miércoles
 *       comida: "Comida del día 10"   cena: "Cena del día 10"
 *
 *     2026-11-14 · plan 27 · semana 2 · sábado → día libre (sin platos)
 *
 * CONCEPTO: DTO de lectura (ADR 0008, como Recipes\Application\RecipeView)
 * El caso de uso no devuelve ni el NutritionPlan (el agregado) ni el
 * ScheduledDay (un value object del dominio): devuelve una foto con
 * PRIMITIVOS ya calculados. Así ningún adaptador (un controlador, un comando
 * de Artisan, el agente del nivel 13) se acopla al modelo interno, y el
 * agregado no sale de la aplicación.
 *
 * Junta datos de DOS objetos del dominio: el número del plan (del agregado)
 * y el día programado (de MealCalendar). Una vista no tiene por qué copiar la
 * forma de una sola clase: se diseña para quien la va a leer.
 *
 * Día libre: `isFreeDay` a true y `lunch`/`dinner` a null. Se prefiere un
 * booleano explícito a obligar a quien lo lee a deducirlo de los null.
 *
 * CAPA: Aplicación (MealPlanning\Application).
 */
final readonly class ScheduledDayView
{
    public function __construct(
        /** La fecha consultada, en formato ISO (Y-m-d), sin hora. */
        public string $date,
        /** El número del plan vigente ese día ("plan 27"). */
        public int $planNumber,
        /** La semana del plan que toca: 1 o 2. */
        public int $weekNumber,
        /** Día de la semana ISO-8601: 1 = lunes … 7 = domingo. */
        public int $weekdayNumber,
        /** El día de la semana en español ("miércoles"). */
        public string $weekday,
        public bool $isFreeDay,
        /** La comida, o null si es el día libre. */
        public ?DishView $lunch,
        /** La cena, o null si es el día libre. */
        public ?DishView $dinner,
    ) {}

    public static function fromScheduledDay(NutritionPlan $plan, ScheduledDay $day): self
    {
        // Un día libre no tiene menú (null): sin platos. No se usa el
        // operador nullsafe ($menu?->lunch()) porque DishView::fromDish()
        // necesita un Dish, no un ?Dish; el ternario deja claro cada caso.
        $menu = $day->menu();

        return new self(
            date: $day->date()->format('Y-m-d'),
            planNumber: $plan->number()->value(),
            weekNumber: $day->weekNumber(),
            weekdayNumber: $day->weekday()->value,
            weekday: $day->weekday()->label(),
            isFreeDay: $day->isFreeDay(),
            lunch: $menu === null ? null : DishView::fromDish($menu->lunch()),
            dinner: $menu === null ? null : DishView::fromDish($menu->dinner()),
        );
    }
}
