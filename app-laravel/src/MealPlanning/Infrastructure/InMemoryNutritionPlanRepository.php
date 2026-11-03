<?php

declare(strict_types=1);

namespace MealPlanning\Infrastructure;

use DateTimeImmutable;
use MealPlanning\Domain\DuplicatedPlanNumber;
use MealPlanning\Domain\DuplicatedPlanStart;
use MealPlanning\Domain\NutritionPlan;
use MealPlanning\Domain\NutritionPlanId;
use MealPlanning\Domain\NutritionPlanRepository;
use MealPlanning\Domain\RecipeReference;

/**
 * InMemoryNutritionPlanRepository: guarda los planes en un array de PHP.
 *
 * CONCEPTO: adaptador en memoria (como Recipes\Infrastructure\InMemoryRecipeRepository)
 * Implementa el puerto NutritionPlanRepository y cumple sus tests de
 * contrato. Lo usan los tests del caso de uso GetMenuForDate (HEXM-0019);
 * el de Eloquent (EloquentNutritionPlanRepository, HEXM-0020) pasa los
 * mismos tests, y por eso los tests rápidos de los casos de uso valen
 * también para producción. Vive en Infrastructure aunque no use Laravel: es
 * un DETALLE de cómo se guardan los planes.
 *
 * DECISIÓN: sin copias defensivas (clone), a diferencia de las recetas
 * InMemoryRecipeRepository clona porque Recipe es mutable (rename()): sin
 * clone, cambiar la receta sin llamar a save() cambiaría también lo
 * "guardado". NutritionPlan es readonly y todo lo que contiene es inmutable,
 * así que compartir el mismo objeto es seguro: nadie puede cambiarlo. Es una
 * de las ventajas prácticas de la inmutabilidad. Si algún día el plan tiene
 * métodos de cambio (nivel 8), habrá que añadir clone aquí, y lo detectará
 * un test de contrato.
 *
 * CAPA: Infraestructura (MealPlanning\Infrastructure).
 */
final class InMemoryNutritionPlanRepository implements NutritionPlanRepository
{
    /**
     * Planes guardados, indexados por el valor de su NutritionPlanId.
     *
     * @var array<string, NutritionPlan>
     */
    private array $plans = [];

    public function save(NutritionPlan $plan): void
    {
        $others = array_filter($this->plans, fn (NutritionPlan $existing): bool => ! $existing->equals($plan));

        // Primero el inicio y luego el número: el orden forma parte del
        // contrato (si choca por las dos cosas, DuplicatedPlanStart).
        foreach ($others as $existing) {
            if (self::calendarDate($existing->startsOn()) === self::calendarDate($plan->startsOn())) {
                throw DuplicatedPlanStart::between($plan, $existing);
            }
        }

        foreach ($others as $existing) {
            if ($existing->number()->equals($plan->number())) {
                throw DuplicatedPlanNumber::between($plan, $existing);
            }
        }

        $this->plans[$plan->id()->value()] = $plan;
    }

    public function find(NutritionPlanId $id): ?NutritionPlan
    {
        return $this->plans[$id->value()] ?? null;
    }

    /**
     * Recorre los planes y se queda con el de inicio más reciente que no
     * pasa de la fecha. Es O(n), pero una persona tiene unos pocos planes al
     * año: la claridad gana. En SQL será WHERE starts_on <= ? ORDER BY
     * starts_on DESC LIMIT 1.
     */
    public function inForceOn(DateTimeImmutable $date): ?NutritionPlan
    {
        $day = self::calendarDate($date);
        $inForce = null;

        foreach ($this->plans as $plan) {
            $starts = self::calendarDate($plan->startsOn());

            if ($starts <= $day && ($inForce === null || $starts > self::calendarDate($inForce->startsOn()))) {
                $inForce = $plan;
            }
        }

        return $inForce;
    }

    /**
     * Ordena por fecha de inicio, del más reciente al más antiguo. En SQL será
     * ORDER BY starts_on DESC.
     */
    public function history(): array
    {
        $plans = array_values($this->plans);
        usort($plans, fn (NutritionPlan $a, NutritionPlan $b): int => self::calendarDate($b->startsOn()) <=> self::calendarDate($a->startsOn()));

        return $plans;
    }

    /**
     * Pregunta a cada plan si la usa (NutritionPlan::usesRecipe()): la regla
     * de "qué es usar una receta" está en el agregado, no aquí. Mismo orden
     * que history().
     */
    public function usingRecipe(RecipeReference $recipe): array
    {
        return array_values(array_filter($this->history(), fn (NutritionPlan $plan): bool => $plan->usesRecipe($recipe)));
    }

    /**
     * La fecha del calendario como texto 'AAAA-MM-DD', tomada en la zona
     * horaria de cada fecha (como MealCalendar). En ese formato, comparar
     * los textos con < y > ordena igual que las fechas: '2026-09-07' <
     * '2026-11-02'. Es el mismo truco que ISO 8601 usa a propósito.
     */
    private static function calendarDate(DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d');
    }
}
