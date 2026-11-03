<?php

declare(strict_types=1);

namespace MealPlanning\Domain;

/**
 * NutritionPlanHistory: los planes de una persona en el tiempo, del más
 * reciente al más antiguo, cada uno con su periodo de vigencia.
 *
 *     plan 27   02/11/2026 → …            (el más reciente: sin fin)
 *     plan 26   07/09/2026 → 01/11/2026   (termina el día antes del 27)
 *     plan 25   06/07/2026 → 06/09/2026
 *
 * CONCEPTO: regla de negocio entre agregados, en el dominio
 * "Un plan está vigente desde su inicio hasta el inicio del plan siguiente"
 * (regla 19, ADR 0010) compara VARIOS planes, así que no la puede responder
 * un NutritionPlan solo. El repositorio ya responde la pregunta puntual
 * "¿cuál rige el día X?" (inForceOn). Para el histórico hace falta la regla
 * completa, y se escribe aquí, en un objeto del dominio que recibe los
 * planes y deriva los periodos: sin Laravel, sin base de datos y sin repetir
 * la lógica en cada caso de uso.
 *
 * Es un value object (inmutable, sin identidad): una foto del histórico en
 * un momento dado. Si se registra un plan nuevo, se construye otro.
 *
 * CAPA: Dominio (MealPlanning\Domain).
 */
final readonly class NutritionPlanHistory
{
    /**
     * @param  list<NutritionPlan>  $plans  del más reciente al más antiguo
     * @param  array<string, ValidityPeriod>  $periods  indexados por id
     */
    private function __construct(
        private array $plans,
        private array $periods,
    ) {}

    /**
     * Ordena los planes por inicio (venga como venga la lista) y deriva el
     * periodo de cada uno: termina el día antes de que empiece el siguiente;
     * el más reciente no tiene fin.
     *
     * @param  list<NutritionPlan>  $plans
     */
    public static function of(array $plans): self
    {
        usort($plans, fn (NutritionPlan $a, NutritionPlan $b): int => $b->startsOn()->format('Y-m-d') <=> $a->startsOn()->format('Y-m-d'));

        $periods = [];
        $nextStart = null;

        // Del más reciente al más antiguo: cada plan acaba cuando empieza el
        // que se acaba de recorrer (el siguiente en el tiempo).
        foreach ($plans as $plan) {
            $periods[$plan->id()->value()] = $nextStart === null
                ? ValidityPeriod::from($plan->startsOn())
                : ValidityPeriod::between($plan->startsOn(), $nextStart->modify('-1 day'));

            $nextStart = $plan->startsOn();
        }

        return new self($plans, $periods);
    }

    /**
     * @return list<NutritionPlan> del más reciente al más antiguo
     */
    public function plans(): array
    {
        return $this->plans;
    }

    /**
     * El periodo de vigencia de un plan del histórico.
     */
    public function periodOf(NutritionPlan $plan): ValidityPeriod
    {
        return $this->periods[$plan->id()->value()]
            ?? throw new \LogicException('El plan no forma parte de este histórico.');
    }
}
