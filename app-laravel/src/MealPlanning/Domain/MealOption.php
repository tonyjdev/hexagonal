<?php

declare(strict_types=1);

namespace MealPlanning\Domain;

/**
 * MealOption: una de las opciones de un grupo "elige N de las siguientes".
 *
 *     "Yogur natural desnatado"
 *     "Tostada de pan integral (40 g) con tomate"
 *
 * CONCEPTO: Value Object
 * Sin identidad, inmutable y comparado por valor. Es texto libre, como los
 * platos de la semana 1 (Dish::described()): así lo escribe la nutricionista,
 * con la cantidad mezclada en la frase. No se intenta separar alimento y
 * cantidad (ver la decisión de DishIngredient sobre la frontera con Recipes).
 *
 * Por qué una clase y no un string: un string vacío o "   " sería una opción
 * válida para PHP; aquí no puede existir. Y la igualdad tiene una regla de
 * negocio propia (sin distinguir mayúsculas ni espacios), que es la que usa
 * OptionGroup para no repetir opciones.
 *
 * CAPA: Dominio (MealPlanning\Domain).
 */
final readonly class MealOption
{
    private function __construct(
        private string $text,
    ) {}

    /**
     * Recorta y deja un solo espacio entre palabras: "Yogur  natural " y
     * "Yogur natural" son la misma opción escrita con prisa.
     */
    public static function of(string $text): self
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '') {
            throw InvalidOptionGroup::emptyOption();
        }

        return new self($text);
    }

    public function text(): string
    {
        return $this->text;
    }

    /**
     * Igualdad por valor, sin distinguir mayúsculas: "Yogur natural" y
     * "yogur natural" son la misma opción.
     */
    public function equals(self $other): bool
    {
        return mb_strtolower($this->text) === mb_strtolower($other->text);
    }
}
