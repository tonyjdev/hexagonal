<?php

declare(strict_types=1);

namespace MealPlanning\Domain;

/**
 * Exchange: un intercambio de la tabla del plan (categoría + alimento +
 * ración).
 *
 *     Hidratos:   Arroz          · 60 g en crudo
 *     Hidratos:   Pan integral   · 40 g
 *     Proteínas:  Pechuga de pollo · 100 g
 *
 * Todos los intercambios de una misma categoría son EQUIVALENTES entre sí:
 * "60 g de arroz" se puede cambiar por "40 g de pan" porque los dos son un
 * intercambio de hidratos.
 *
 * CONCEPTO: Value Object (sin identidad, inmutable, igualdad por valor)
 *
 * Por qué lleva su categoría dentro: un intercambio solo tiene sentido en
 * su categoría (el arroz es "1 intercambio DE HIDRATOS"), y así la tabla
 * puede recibir una lista plana y agruparla ella misma (ver ExchangeTable).
 * Un mismo alimento puede aparecer en dos categorías con raciones distintas
 * (la legumbre como hidrato y como proteína): son dos Exchange distintos.
 *
 * DECISIÓN: la ración es texto ("60 g en crudo", "1 pieza mediana"), por el
 * mismo motivo que la cantidad de DishIngredient: respetar la frontera con
 * el recetario (su Quantity) y no adelantar cálculos que todavía nadie pide.
 * A diferencia de DishIngredient, aquí la ración es OBLIGATORIA: una
 * equivalencia sin cantidad no dice nada ("arroz" ¿cuánto?).
 *
 * CAPA: Dominio (MealPlanning\Domain).
 */
final readonly class Exchange
{
    private function __construct(
        private ExchangeCategory $category,
        private string $food,
        private string $serving,
    ) {}

    public static function of(ExchangeCategory $category, string $food, string $serving): self
    {
        $food = self::normalize($food);
        $serving = self::normalize($serving);

        if ($food === '') {
            throw InvalidExchangeTable::exchangeWithoutFood($category);
        }

        if ($serving === '') {
            throw InvalidExchangeTable::exchangeWithoutServing($food);
        }

        return new self($category, $food, $serving);
    }

    public function category(): ExchangeCategory
    {
        return $this->category;
    }

    public function food(): string
    {
        return $this->food;
    }

    public function serving(): string
    {
        return $this->serving;
    }

    /**
     * Igualdad por valor: misma categoría, mismo alimento (sin distinguir
     * mayúsculas, como los ingredientes) y misma ración, tal cual.
     */
    public function equals(self $other): bool
    {
        return $this->category === $other->category
            && mb_strtolower($this->food) === mb_strtolower($other->food)
            && $this->serving === $other->serving;
    }

    private static function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
