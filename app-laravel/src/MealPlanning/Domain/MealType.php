<?php

declare(strict_types=1);

namespace MealPlanning\Domain;

/**
 * MealType: las cinco comidas del día de un plan nutricional.
 *
 * CONCEPTO: enum como tipo del dominio (igual que Recipes\Domain\Unit)
 * Un conjunto CERRADO de valores: no existe una "merienda-cena" inventada.
 * El valor del backed enum ('lunch', 'dinner'...) va en inglés porque es el
 * que se guardará en la base de datos y viajará por la API; el texto para la
 * persona lo da label().
 *
 * Los casos están en el orden en que se comen a lo largo del día, que es el
 * orden en que los listará MealType::cases() (útil para mostrar un día).
 *
 * En el negocio hay dos grupos (ver docs/DOMINIO.md):
 *   - Pautas fijas (FixedMeal, HEXM-0014): desayuno, media mañana y media
 *     tarde; iguales todos los días.
 *   - Comidas del menú (Dish en MenuDay): comida y cena; cambian cada día.
 * De momento no se añade un método isFixed(): se escribirá cuando alguna
 * pieza del dominio lo necesite de verdad.
 *
 * CAPA: Dominio (MealPlanning\Domain).
 */
enum MealType: string
{
    case Breakfast = 'breakfast';
    case MidMorning = 'mid_morning';
    case Lunch = 'lunch';
    case AfternoonSnack = 'afternoon_snack';
    case Dinner = 'dinner';

    /**
     * Nombre de la comida en español.
     *
     * `match` sin `default`: si se añade un caso al enum y no se le da
     * etiqueta aquí, PHPStan avisa ("Match expression does not handle
     * remaining value") y, si llegara a ejecutarse, PHP lanzaría
     * UnhandledMatchError.
     */
    public function label(): string
    {
        return match ($this) {
            self::Breakfast => 'desayuno',
            self::MidMorning => 'media mañana',
            self::Lunch => 'comida',
            self::AfternoonSnack => 'media tarde',
            self::Dinner => 'cena',
        };
    }
}
