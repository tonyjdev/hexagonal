<?php

declare(strict_types=1);

namespace MealPlanning\Domain;

use Shared\Domain\DomainException;

/**
 * DuplicatedPlanNumber: ya hay otro plan con el mismo número.
 *
 * CONCEPTO: otra regla entre agregados en el contrato del repositorio
 * (como DuplicatedPlanStart, ADR 0011)
 * La nutricionista numera sus planes en orden (25, 26, 27…) y el histórico
 * los muestra por número ("¿qué cenaba en el plan 25?"). Dos planes
 * distintos con el mismo número harían ambigua esa pregunta, así que el
 * número es ÚNICO (regla 22, decisión del domain_issue de HEXM-0021,
 * resuelto en HEXM-0023). Ningún plan puede comprobarlo solo: lo comprueba
 * el repositorio al guardar, y en PostgreSQL lo garantiza un índice único.
 *
 * El número sigue sin ser la IDENTIDAD del plan (lo es el NutritionPlanId):
 * volver a guardar el mismo plan, con su mismo id, no es un duplicado.
 *
 * CAPA: Dominio (MealPlanning\Domain). Ver Shared\Domain\DomainException.
 */
final class DuplicatedPlanNumber extends DomainException
{
    public static function between(NutritionPlan $new, NutritionPlan $existing): self
    {
        return new self(sprintf(
            'Ya existe un plan %d (empieza el %s): no puede haber dos planes con el mismo número.',
            $new->number()->value(),
            $existing->startsOn()->format('d/m/Y'),
        ));
    }
}
