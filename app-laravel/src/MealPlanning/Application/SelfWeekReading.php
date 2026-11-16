<?php

declare(strict_types=1);

namespace MealPlanning\Application;

/**
 * SelfWeekReading: lo que se ha leído del Excel de la semana 2 del usuario.
 *
 * Los días 8 a 14, cada uno con su comida y su cena (nombre corto e
 * ingredientes); el 13, el sábado libre, con las dos a null. Los
 * ingredientes llegan con su texto entero y la cantidad a null: separarla es
 * trabajo del nivel 9 (ADR 0023).
 *
 * Como NutritionistPlanReading: datos primitivos con la forma de self_week
 * en RegisterNutritionPlanCommand (ADR 0014), no objetos del dominio. La
 * semana se convierte en un MenuWeek cuando se da de alta el plan, con
 * MenuWeek::fromSelfDays(), que es quien sabe que el día 13 es el sábado.
 *
 * CAPA: Aplicación (MealPlanning\Application): salida del puerto PlanReader.
 */
final readonly class SelfWeekReading
{
    /**
     * @param  list<array{day: int, lunch: array{name: string, ingredients: list<array{name: string, quantity: string|null}>}|null, dinner: array{name: string, ingredients: list<array{name: string, quantity: string|null}>}|null}>  $selfWeek
     */
    public function __construct(
        public array $selfWeek,
    ) {}
}
