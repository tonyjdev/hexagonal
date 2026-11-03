<?php

declare(strict_types=1);

namespace MealPlanning\Domain;

use Shared\Domain\DomainException;

/**
 * DuplicatedPlanStart: ya hay otro plan que empieza el mismo día.
 *
 * CONCEPTO: error de dominio que lanza un adaptador
 * La regla ("un solo plan vigente en cada fecha", regla 5) es del dominio y
 * la excepción también, aunque quien la detecta es el adaptador del
 * repositorio, porque es el único que ve todos los planes. En el nivel 5,
 * el adaptador de Eloquent traducirá la violación del índice único de
 * PostgreSQL a esta misma excepción: el caso de uso ve siempre un error de
 * negocio en español, nunca un QueryException de Laravel.
 *
 * No se llama InvalidNutritionPlan porque el plan, solo, es válido: el
 * problema es su relación con OTRO plan.
 *
 * CAPA: Dominio (MealPlanning\Domain). Ver Shared\Domain\DomainException.
 */
final class DuplicatedPlanStart extends DomainException
{
    public static function between(NutritionPlan $new, NutritionPlan $existing): self
    {
        return new self(sprintf(
            'El plan %d no puede empezar el %s: ese día ya empieza el plan %d.',
            $new->number()->value(),
            $new->startsOn()->format('d/m/Y'),
            $existing->number()->value(),
        ));
    }
}
