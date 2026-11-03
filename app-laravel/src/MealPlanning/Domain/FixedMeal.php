<?php

declare(strict_types=1);

namespace MealPlanning\Domain;

/**
 * FixedMeal: la pauta de una comida fija del plan (desayuno, media mañana o
 * media tarde), igual todos los días y en las dos semanas.
 *
 *     Desayuno: "Café con leche desnatada"            ← parte fija
 *               + elegir 1 de las siguientes            ← OptionGroup
 *                   - Tostada de pan integral (40 g) con tomate
 *                   - 30 g de copos de avena
 *
 *     Media mañana: elegir 2 de las siguientes          ← solo opciones
 *                   - 1 pieza de fruta · Yogur natural · 20 g de nueces
 *
 * CONCEPTO: value object compuesto (de otros value objects)
 * Una pauta no tiene identidad propia: si el plan siguiente trae otro
 * desayuno, es OTRA pauta, no "la misma cambiada". Por eso es inmutable y
 * se compara por valor, y lo que contiene (OptionGroup, MealOption) también.
 *
 * DECISIÓN: la parte fija es texto libre (opcional)
 * Es lo que se toma siempre, sin elegir. Se guarda como la escribe la
 * nutricionista, igual que un plato de la semana 1 (Dish::described()):
 * con saltos de línea y cantidades dentro de la frase. Es opcional porque
 * hay pautas que son SOLO un "elige N" (la media mañana del ejemplo).
 *
 * INVARIANTES:
 *   1. Solo para comidas fijas (MealType::isFixed()): la comida y la cena
 *      cambian cada día y van en MenuDay.
 *   2. Nunca vacía: tiene parte fija, al menos un grupo de opciones, o ambas.
 *
 * Que el plan no repita pautas (dos desayunos) es una regla del CONJUNTO de
 * pautas: la vigilará el agregado NutritionPlan (HEXM-0015), igual que
 * MenuWeek vigila que no se repitan días.
 *
 * CAPA: Dominio (MealPlanning\Domain).
 */
final readonly class FixedMeal
{
    /**
     * @param  list<OptionGroup>  $optionGroups
     */
    private function __construct(
        private MealType $mealType,
        private ?string $description,
        private array $optionGroups,
    ) {}

    /**
     * FixedMeal::of(MealType::Breakfast, 'Café con leche desnatada', [$grupo]).
     *
     * La descripción se recorta pero conserva sus saltos de línea (texto
     * libre de un documento, como en Dish::described()). En blanco cuenta
     * como "sin parte fija".
     *
     * @param  array<OptionGroup>  $optionGroups
     */
    public static function of(MealType $mealType, ?string $description, array $optionGroups = []): self
    {
        if (! $mealType->isFixed()) {
            throw InvalidFixedMeal::notAFixedMeal($mealType);
        }

        $description = $description === null ? null : trim($description);
        $description = $description === '' ? null : $description;
        $optionGroups = array_values($optionGroups);

        if ($description === null && $optionGroups === []) {
            throw InvalidFixedMeal::empty($mealType);
        }

        return new self($mealType, $description, $optionGroups);
    }

    public function mealType(): MealType
    {
        return $this->mealType;
    }

    /**
     * Lo que se toma siempre, o null si la pauta es solo de opciones.
     */
    public function description(): ?string
    {
        return $this->description;
    }

    /**
     * @return list<OptionGroup>
     */
    public function optionGroups(): array
    {
        return $this->optionGroups;
    }

    public function equals(self $other): bool
    {
        if ($this->mealType !== $other->mealType
            || $this->description !== $other->description
            || count($this->optionGroups) !== count($other->optionGroups)) {
            return false;
        }

        foreach ($this->optionGroups as $position => $group) {
            if (! $group->equals($other->optionGroups[$position])) {
                return false;
            }
        }

        return true;
    }
}
