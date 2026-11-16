<?php

declare(strict_types=1);

namespace MealPlanning\Domain;

/**
 * PlanImportStatus: en qué punto está una importación de plan.
 *
 *     AwaitingSelfWeek ──(semana 2)──► ReadyToConfirm ──(fecha de inicio)──► Confirmed
 *          ▲   │ (otro Excel de la nutricionista)  ▲   │ (otro Excel de
 *          └───┘                                   └───┘  cualquiera de los dos)
 *
 * CONCEPTO: ciclo de vida (lifecycle) de un agregado
 * Un proceso de varios pasos se modela como un agregado con ESTADOS y
 * transiciones permitidas, en vez de con banderas sueltas (is_complete,
 * is_done…) que podrían contradecirse. Cada método de PlanImport es una
 * transición, y la que no está en el dibujo (confirmar sin semana 2, cambiar
 * una importación confirmada) se rechaza con un error del negocio.
 *
 * DECISIÓN: el estado se DERIVA, no se guarda aparte
 * PlanImport no tiene un campo $status: lo calcula de lo que tiene (¿tiene
 * semana 2?, ¿tiene el plan que se creó al confirmar?). Como la vigencia de
 * un plan (ADR 0010): un dato que se deduce de otros no se guarda dos veces,
 * así nunca pueden discrepar. (La tabla sí lo guarda, como columna para leer
 * y comprobar con un CHECK: es un detalle de persistencia, ADR 0025.)
 *
 * Valores en snake_case, como los de MealType: son los que se guardan y los
 * que verá la API (HEXM-0033).
 *
 * CAPA: Dominio (MealPlanning\Domain).
 */
enum PlanImportStatus: string
{
    /** Tiene el Excel de la nutricionista; falta el de la semana 2. */
    case AwaitingSelfWeek = 'awaiting_self_week';

    /** Tiene los dos Excel: solo falta la fecha de inicio para confirmarla. */
    case ReadyToConfirm = 'ready_to_confirm';

    /** Ya se convirtió en un NutritionPlan: no se puede cambiar. */
    case Confirmed = 'confirmed';

    /**
     * Nombre en español, para los mensajes y las pantallas.
     */
    public function label(): string
    {
        return match ($this) {
            self::AwaitingSelfWeek => 'pendiente de la semana 2',
            self::ReadyToConfirm => 'lista para confirmar',
            self::Confirmed => 'confirmada',
        };
    }
}
