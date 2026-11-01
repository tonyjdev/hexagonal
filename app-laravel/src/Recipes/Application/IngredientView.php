<?php

declare(strict_types=1);

namespace Recipes\Application;

use Recipes\Domain\Ingredient;

/**
 * IngredientView: un ingrediente listo para mostrar.
 *
 * Forma parte del DTO de lectura RecipeView (ver allí el porqué). Todo son
 * primitivos ya calculados:
 *
 *   - amount: el número como lo escribe una persona ("½", "1 ½", "200"), o
 *     null si es al gusto. Lo calcula el dominio (Quantity::amountAsText()).
 *   - unit: el código de la unidad ("jar"), el MISMO que se envía al crear la
 *     receta, para que un cliente pueda reenviar lo que lee.
 *   - unitLabel: el nombre en español ("bote"), para mostrarlo a una persona
 *     (Unit::label()).
 *   - toTaste: true si es "al gusto". Es redundante con amount === null,
 *     pero hace explícito el concepto del negocio: quien lee la vista no
 *     tiene que saber que un null significa "al gusto".
 *
 * CAPA: Aplicación (Recipes\Application).
 */
final readonly class IngredientView
{
    public function __construct(
        public string $name,
        public ?string $amount,
        public ?string $unit,
        public ?string $unitLabel,
        public bool $toTaste,
    ) {}

    public static function fromIngredient(Ingredient $ingredient): self
    {
        $quantity = $ingredient->quantity();

        return new self(
            name: $ingredient->name(),
            amount: $quantity?->amountAsText(),
            unit: $quantity?->unit()->value,
            unitLabel: $quantity?->unit()->label(),
            toTaste: $ingredient->isToTaste(),
        );
    }
}
