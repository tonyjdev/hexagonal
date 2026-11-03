<?php

declare(strict_types=1);

namespace MealPlanning\Domain;

use Shared\Domain\DomainException;

/**
 * InvalidMenuWeek: semana de menú no válida (número de semana, días que
 * faltan o sobran, días repetidos o numeración de origen desconocida).
 *
 * CAPA: Dominio (MealPlanning\Domain). Ver Shared\Domain\DomainException.
 */
final class InvalidMenuWeek extends DomainException
{
    public static function unknownNumber(int $number): self
    {
        return new self(sprintf('Un plan tiene la semana 1 y la semana 2: la semana %d no existe.', $number));
    }

    public static function wrongNumberOfDays(int $days): self
    {
        return new self(sprintf(
            'Una semana debe tener 6 días con menú (de lunes a viernes y el domingo), y esta tiene %d.',
            $days,
        ));
    }

    public static function duplicatedDay(Weekday $weekday): self
    {
        return new self(sprintf('El %s aparece más de una vez en la semana.', $weekday->label()));
    }

    public static function unknownNutritionistMenu(int $menu): self
    {
        return new self(sprintf('La semana 1 numera los menús del 1 al 6: el menú %d no existe.', $menu));
    }

    public static function unknownSelfDay(int $day): self
    {
        return new self(sprintf('La semana 2 numera los días del 8 al 14: el día %d no existe.', $day));
    }

    /**
     * El mismo menú dos veces en la semana de la nutricionista. Se dice con
     * el número de menú (el lenguaje del documento) y no con el día de la
     * semana, porque es lo que el usuario ha escrito (HEXM-0021).
     */
    public static function duplicatedNutritionistMenu(int $menu): self
    {
        return new self(sprintf('El menú %d aparece más de una vez en la semana 1.', $menu));
    }

    /**
     * El mismo día dos veces en la semana del usuario (HEXM-0021).
     */
    public static function duplicatedSelfDay(int $day): self
    {
        return new self(sprintf('El día %d aparece más de una vez en la semana 2.', $day));
    }
}
