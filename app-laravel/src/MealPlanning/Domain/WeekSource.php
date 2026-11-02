<?php

declare(strict_types=1);

namespace MealPlanning\Domain;

/**
 * WeekSource: quién ha elaborado una semana de menú.
 *
 * CONCEPTO: enum como tipo del dominio (ver MealType y Recipes\Domain\Unit)
 *
 * - Nutritionist: la semana 1, que entrega la nutricionista con menús
 *   numerados del 1 al 6 y platos en texto libre.
 * - Self: la semana 2, que elabora el propio usuario, con nombre corto y
 *   lista de ingredientes por plato.
 *
 * Por qué importa en el dominio: el formato de los platos depende del origen
 * (ver Dish), y algunas reglas solo se validan en la semana que elabora el
 * usuario, como "la grasa solo en la comida o en la cena" (nivel 8).
 *
 * DECISIÓN: se llama `Self`, como dice el glosario. Es una palabra reservada
 * en minúsculas (`self::`), pero PHP permite `Self` como nombre de caso de
 * enum, y dentro del enum se escribe self::Self.
 *
 * CAPA: Dominio (MealPlanning\Domain).
 */
enum WeekSource: string
{
    case Nutritionist = 'nutritionist';
    case Self = 'self';

    /**
     * Quién elaboró la semana, en español. `match` sin `default` (ver MealType).
     */
    public function label(): string
    {
        return match ($this) {
            self::Nutritionist => 'nutricionista',
            self::Self => 'elaboración propia',
        };
    }
}
