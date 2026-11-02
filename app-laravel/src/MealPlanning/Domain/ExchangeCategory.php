<?php

declare(strict_types=1);

namespace MealPlanning\Domain;

/**
 * ExchangeCategory: las categorías de la tabla de intercambios del plan.
 *
 * CONCEPTO: enum como vocabulario cerrado del negocio (ver MealType)
 *
 * Un intercambio es una equivalencia dentro de una categoría: "60 g de arroz"
 * se puede cambiar por "80 g de pasta" porque los dos cuentan como un
 * intercambio de hidratos. La tabla (ExchangeTable) llegará en una tarea
 * posterior del nivel 3; aquí solo queda fijado el vocabulario.
 *
 * CAPA: Dominio (MealPlanning\Domain).
 */
enum ExchangeCategory: string
{
    case Carbohydrates = 'carbohydrates';
    case Protein = 'protein';
    case Vegetables = 'vegetables';
    case Fat = 'fat';

    /**
     * Nombre de la categoría en español, como aparece en los planes.
     * `match` sin `default` (ver MealType).
     */
    public function label(): string
    {
        return match ($this) {
            self::Carbohydrates => 'hidratos',
            self::Protein => 'proteínas',
            self::Vegetables => 'vegetales',
            self::Fat => 'grasas',
        };
    }
}
