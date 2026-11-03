<?php

declare(strict_types=1);

namespace MealPlanning\Domain;

/**
 * MenuDay: el menú de un día del plan (su comida y su cena).
 *
 * CONCEPTO: value object dentro de un agregado (y no entidad)
 * El glosario decía "entidad", y en HEXM-0013 se corrigió. La pregunta que
 * decide es: ¿necesitamos seguir a ESTE día a lo largo del tiempo aunque
 * cambien sus datos? Con Recipe, sí: renombrada sigue siendo la misma receta.
 * Con un día de menú, no: si el usuario cambia la cena del martes, basta con
 * sustituir el MenuDay entero por otro nuevo. No hay ciclo de vida propio.
 *
 * Es verdad que un día "se identifica" por su día de la semana, pero eso es
 * una CLAVE dentro de su semana (no puede haber dos martes), no una identidad
 * global: un martes no tiene sentido fuera de su MenuWeek. Esa unicidad la
 * vigila MenuWeek, que es quien tiene la colección (ver MenuWeek).
 *
 * Al ser value object: es inmutable (readonly) y se compara por valor
 * (mismo día, misma comida, misma cena).
 *
 * INVARIANTES (reglas 2 y 3 de docs/DOMINIO.md):
 *   - siempre tiene comida (Lunch) y cena (Dinner);
 *   - nunca cae en el día libre (sábado). Se comprueba AQUÍ y no solo en la
 *     semana para que no pueda existir, ni un instante, un "menú del sábado"
 *     (modelo siempre válido, como Recipe).
 *
 * CAPA: Dominio (MealPlanning\Domain).
 */
final readonly class MenuDay
{
    private function __construct(
        private Weekday $weekday,
        private Dish $lunch,
        private Dish $dinner,
    ) {}

    /**
     * Crea el menú de un día comprobando sus invariantes.
     *
     * DECISIÓN: ¿por qué ?Dish si la comida y la cena son obligatorias?
     * Con `Dish` a secas, un día sin cena reventaría con un TypeError de PHP:
     * un error técnico, en inglés, que no explica nada al usuario. Pero estos
     * datos llegarán de documentos externos (el importador del nivel 7, la
     * semana 2 escrita a mano) donde una cena SÍ puede faltar, y eso es un
     * error de NEGOCIO que merece su mensaje en español (InvalidMenuDay).
     * Por dentro el objeto siempre está completo: los getters devuelven Dish,
     * nunca null.
     */
    public static function of(Weekday $weekday, ?Dish $lunch, ?Dish $dinner): self
    {
        if ($weekday->isFreeDay()) {
            throw InvalidMenuDay::onFreeDay($weekday);
        }

        if ($lunch === null) {
            throw InvalidMenuDay::withoutMeal($weekday, MealType::Lunch);
        }

        if ($dinner === null) {
            throw InvalidMenuDay::withoutMeal($weekday, MealType::Dinner);
        }

        return new self($weekday, $lunch, $dinner);
    }

    public function weekday(): Weekday
    {
        return $this->weekday;
    }

    public function lunch(): Dish
    {
        return $this->lunch;
    }

    public function dinner(): Dish
    {
        return $this->dinner;
    }

    /**
     * Igualdad por valor: mismo día de la semana y mismos platos. Delega en
     * Dish::equals() (composición de value objects).
     */
    public function equals(self $other): bool
    {
        return $this->weekday === $other->weekday
            && $this->lunch->equals($other->lunch)
            && $this->dinner->equals($other->dinner);
    }
}
