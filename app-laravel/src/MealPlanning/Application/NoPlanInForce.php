<?php

declare(strict_types=1);

namespace MealPlanning\Application;

use DateTimeImmutable;
use Shared\Application\NotFound;

/**
 * NoPlanInForce: no hay ningún plan nutricional vigente en esa fecha.
 *
 * Pasa con una fecha anterior al primer plan, o cuando aún no se ha dado de
 * alta ninguno. La lanza GetMenuForDate cuando el repositorio no encuentra
 * un plan vigente (inForceOn() devuelve null).
 *
 * CONCEPTO: "no encontrado" como resultado de una consulta (ADR 0008)
 * Como RecipeNotFound, hereda de Shared\Application\NotFound y no de
 * DomainException: no se ha incumplido ninguna regla del negocio, es que no
 * hay nada que responder. Cuando llegue la API (nivel 5), el manejador
 * global la convertirá en un 404 sin conocer esta clase.
 *
 * CAPA: Aplicación (MealPlanning\Application).
 */
final class NoPlanInForce extends NotFound
{
    public static function on(DateTimeImmutable $date): self
    {
        // d/m/Y: la fecha como la escribe el usuario en español.
        return new self(sprintf('No hay ningún plan nutricional vigente el %s.', $date->format('d/m/Y')));
    }
}
