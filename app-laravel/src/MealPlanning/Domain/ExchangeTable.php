<?php

declare(strict_types=1);

namespace MealPlanning\Domain;

/**
 * ExchangeTable: la tabla de intercambios de un plan, con sus reglas.
 *
 *     Hidratos:  Arroz 60 g en crudo · Pan integral 40 g · Patata 200 g
 *     Proteínas: Pechuga de pollo 100 g · Huevos 2 unidades
 *     Reglas:
 *       1. La legumbre cuenta como ½ intercambio de proteína.
 *       2. La grasa solo en la comida o en la cena, no en ambas.
 *
 * CONCEPTO: colección inmutable agrupada por un enum
 * Recibe una lista plana de Exchange (cada uno sabe su categoría) y la
 * guarda agrupada por categoría, conservando el orden en que llegan (el del
 * documento). PHP no admite un enum como clave de array, así que por dentro
 * se agrupa por su valor (ExchangeCategory->value: 'carbohydrates'...). Ese
 * detalle no sale de la clase: se pregunta con exchangesIn(Category).
 *
 * CONCEPTO: datos con reglas que todavía no se ejecutan
 * Las reglas de los intercambios se GUARDAN con el plan, como texto y en
 * orden, porque son parte de lo que entrega la nutricionista y pueden
 * cambiar de un plan a otro (regla 4). HACERLAS CUMPLIR contra un menú
 * ("¿hay grasa en la comida Y en la cena?") es otra cosa, y llegará en el
 * nivel 8, cuando el usuario elabore su semana 2. Ahí se decidirá si alguna
 * regla pasa a ser código (un objeto con comportamiento) en lugar de texto.
 * Hoy el dominio solo garantiza que no se pierden ni se desordenan.
 *
 * Inmutable y comparada por valor. Una tabla vacía es válida para esta
 * clase: si un plan DEBE tener intercambios, lo decidirá NutritionPlan
 * (HEXM-0015).
 *
 * CAPA: Dominio (MealPlanning\Domain).
 */
final readonly class ExchangeTable
{
    /**
     * @param  array<string, list<Exchange>>  $exchangesByCategory  clave = ExchangeCategory->value
     * @param  list<string>  $rules
     */
    private function __construct(
        private array $exchangesByCategory,
        private array $rules,
    ) {}

    /**
     * @param  array<Exchange>  $exchanges  en el orden del documento
     * @param  array<string>  $rules  en el orden del documento
     */
    public static function of(array $exchanges, array $rules = []): self
    {
        $byCategory = [];

        foreach ($exchanges as $exchange) {
            $byCategory[$exchange->category()->value][] = $exchange;
        }

        $validRules = [];

        foreach ($rules as $rule) {
            // Se recorta pero no se compactan los espacios interiores: es
            // texto libre del documento, como Dish::described().
            $rule = trim($rule);

            if ($rule === '') {
                throw InvalidExchangeTable::emptyRule();
            }

            $validRules[] = $rule;
        }

        return new self($byCategory, $validRules);
    }

    /**
     * Los intercambios de una categoría, en el orden del documento ([] si no
     * tiene ninguno).
     *
     * @return list<Exchange>
     */
    public function exchangesIn(ExchangeCategory $category): array
    {
        return $this->exchangesByCategory[$category->value] ?? [];
    }

    /**
     * Las reglas del plan, en texto y en su orden original.
     *
     * @return list<string>
     */
    public function rules(): array
    {
        return $this->rules;
    }

    /**
     * Igualdad por valor: los mismos intercambios en cada categoría (en el
     * mismo orden) y las mismas reglas (en el mismo orden). El orden de las
     * categorías entre sí no importa: se recorren todas con cases().
     */
    public function equals(self $other): bool
    {
        if ($this->rules !== $other->rules) {
            return false;
        }

        foreach (ExchangeCategory::cases() as $category) {
            $mine = $this->exchangesIn($category);
            $theirs = $other->exchangesIn($category);

            if (count($mine) !== count($theirs)) {
                return false;
            }

            foreach ($mine as $position => $exchange) {
                if (! $exchange->equals($theirs[$position])) {
                    return false;
                }
            }
        }

        return true;
    }
}
