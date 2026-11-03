<?php

declare(strict_types=1);

namespace MealPlanning\Domain;

use DateTimeInterface;

/**
 * Weekday: un día de la semana (lunes a domingo).
 *
 * CONCEPTO: enum respaldado por un entero (int-backed enum)
 * MealType y WeekSource guardan un texto ('lunch', 'self'); este enum guarda
 * un ENTERO, y no uno cualquiera: el de la norma ISO-8601, que es el que da
 * PHP con $date->format('N') (1 = lunes ... 7 = domingo). Así tenemos lo
 * mejor de las dos opciones que se barajaron en HEXM-0013:
 *
 *   - Frente a un int "a pelo": el tipo es CERRADO. Un parámetro Weekday no
 *     admite un 0 ni un 9, no hay que validarlo a mano, y el código se lee en
 *     el lenguaje del negocio (Weekday::Sunday en lugar de 7).
 *   - Frente a un enum de texto: convertir una fecha en día de la semana es
 *     trivial (Weekday::fromDate(), que usa $date->format('N')), y el orden
 *     natural del enum (lunes → domingo) es el de sus valores.
 *
 * Por qué ISO y no el formato 'w' de PHP (0 = domingo): en el negocio la
 * semana del plan EMPIEZA EN LUNES (docs/DOMINIO.md), igual que en ISO.
 *
 * CAPA: Dominio (MealPlanning\Domain).
 */
enum Weekday: int
{
    case Monday = 1;
    case Tuesday = 2;
    case Wednesday = 3;
    case Thursday = 4;
    case Friday = 5;
    case Saturday = 6;
    case Sunday = 7;

    /**
     * El día de la semana en que cae una fecha.
     *
     * CONCEPTO: constructor con nombre en un enum
     * Un enum también puede tener métodos estáticos. Este esconde el detalle
     * técnico (el formato 'N' de PHP, la numeración ISO) tras una pregunta
     * del negocio: "¿qué día de la semana es esta fecha?". Lo usa
     * NutritionPlan (la fecha de inicio cae en lunes, HEXM-0015) y lo usará
     * el calendario del nivel 4 ("¿qué me toca hoy?").
     *
     * Recibe DateTimeInterface (la interfaz nativa de PHP que cumplen
     * DateTime y DateTimeImmutable, y también Carbon, que hereda de ellas):
     * así no obliga a nada a quien lo llame.
     */
    public static function fromDate(DateTimeInterface $date): self
    {
        return self::from((int) $date->format('N'));
    }

    /**
     * Nombre del día en español, en minúsculas (como se escribe en mitad de
     * una frase: "el domingo no tiene menú"). `match` sin `default`: ver
     * MealType::label().
     */
    public function label(): string
    {
        return match ($this) {
            self::Monday => 'lunes',
            self::Tuesday => 'martes',
            self::Wednesday => 'miércoles',
            self::Thursday => 'jueves',
            self::Friday => 'viernes',
            self::Saturday => 'sábado',
            self::Sunday => 'domingo',
        };
    }

    /**
     * ¿Es el día libre del plan (FreeDay)?
     *
     * CONCEPTO: la regla de negocio, con nombre propio
     * "El sábado es libre" se pregunta así (Weekday::Saturday->isFreeDay())
     * en lugar de repetir `=== Weekday::Saturday` por el código. Si algún día
     * la nutricionista cambiase el día libre, se tocaría en un único sitio.
     */
    public function isFreeDay(): bool
    {
        return $this === self::Saturday;
    }
}
