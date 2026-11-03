<?php

declare(strict_types=1);

namespace MealPlanning\Application;

/**
 * PlanStatus: cómo está un plan respecto a HOY: vigente, finalizado o
 * próximo (registrado pero aún no empezado).
 *
 * CONCEPTO: estado derivado del tiempo, que no se guarda
 * El estado de un plan no es un campo de la base de datos: depende de qué
 * día es hoy. El 1 de noviembre el plan 26 está vigente; el 2, finalizado.
 * Guardarlo obligaría a actualizarlo cada día. Se calcula al consultar, con
 * el periodo del plan (ValidityPeriod, dominio) y la fecha del Clock.
 *
 * Vive en Application y no en el dominio porque es una forma de PRESENTAR el
 * periodo respecto a una fecha, propia del listado del histórico; la regla
 * de fondo (qué periodo rige cada plan) sí es del dominio.
 *
 * CAPA: Aplicación (MealPlanning\Application).
 */
enum PlanStatus: string
{
    case InForce = 'in_force';
    case Finished = 'finished';
    case Upcoming = 'upcoming';

    /**
     * Etiqueta en español para mostrarlo ("vigente").
     */
    public function label(): string
    {
        return match ($this) {
            self::InForce => 'vigente',
            self::Finished => 'finalizado',
            self::Upcoming => 'próximo',
        };
    }
}
