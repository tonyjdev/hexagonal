<?php

declare(strict_types=1);

namespace MealPlanning\Domain;

/**
 * OptionGroup: "elegir N de las siguientes" dentro de una pauta fija.
 *
 *     Media mañana: elegir 2 de las siguientes
 *       - 1 pieza de fruta
 *       - Yogur natural desnatado
 *       - 20 g de frutos secos
 *
 * CONCEPTO: value object compuesto con su propia invariante
 * Un grupo está hecho de piezas que ya son válidas por sí mismas (cada
 * MealOption tiene texto), pero el GRUPO tiene reglas que no se ven mirando
 * una opción suelta: relacionan N con la lista entera.
 *   1. N es al menos 1: "elige 0" no es una elección.
 *   2. N no supera el número de opciones: no se pueden elegir 3 de 2.
 *      "Elige 2 de 2" sí es válido (en la práctica, tomar las dos).
 *   3. No hay opciones repetidas (MealOption::equals: sin distinguir
 *      mayúsculas). Con una repetida, "elige 2" podría acabar siendo la
 *      misma cosa dos veces.
 * De 1 y 2 se deduce que siempre hay al menos una opción.
 *
 * Por qué "choose" y no "min/max": en los planes siempre es un número
 * exacto ("elegir 2"). Si algún día aparece "elige hasta 2", se cambiará con
 * un caso real delante, no por si acaso.
 *
 * Inmutable y comparado por valor (mismo N y mismas opciones, en el mismo
 * orden: el orden es el del documento, y se respeta al mostrarlo).
 *
 * CAPA: Dominio (MealPlanning\Domain).
 */
final readonly class OptionGroup
{
    /**
     * @param  list<MealOption>  $options
     */
    private function __construct(
        private int $choose,
        private array $options,
    ) {}

    /**
     * OptionGroup::choose(2, [MealOption::of('Fruta'), MealOption::of('Yogur')])
     * se lee como el documento: "elige 2 de estas".
     *
     * @param  array<MealOption>  $options
     */
    public static function choose(int $count, array $options): self
    {
        $options = array_values($options);

        if ($count < 1) {
            throw InvalidOptionGroup::chooseAtLeastOne($count);
        }

        if ($count > count($options)) {
            throw InvalidOptionGroup::moreThanAvailable($count, count($options));
        }

        self::guardAgainstDuplicatedOptions($options);

        return new self($count, $options);
    }

    /**
     * Cuántas opciones hay que elegir (la N de "elige N").
     */
    public function count(): int
    {
        return $this->choose;
    }

    /**
     * @return list<MealOption>
     */
    public function options(): array
    {
        return $this->options;
    }

    public function equals(self $other): bool
    {
        if ($this->choose !== $other->choose || count($this->options) !== count($other->options)) {
            return false;
        }

        foreach ($this->options as $position => $option) {
            if (! $option->equals($other->options[$position])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Compara cada opción con las anteriores (O(n²), como en Recipe: un grupo
     * tiene un puñado de opciones y la claridad gana).
     *
     * @param  list<MealOption>  $options
     */
    private static function guardAgainstDuplicatedOptions(array $options): void
    {
        foreach ($options as $position => $option) {
            foreach (array_slice($options, 0, $position) as $previous) {
                if ($option->equals($previous)) {
                    throw InvalidOptionGroup::duplicatedOption($option->text());
                }
            }
        }
    }
}
