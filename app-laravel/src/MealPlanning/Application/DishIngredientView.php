<?php

declare(strict_types=1);

namespace MealPlanning\Application;

use MealPlanning\Domain\DishIngredient;

/**
 * DishIngredientView: un ingrediente de un plato, listo para mostrar.
 *
 * La cantidad ya es texto en el dominio ("80 g", "½ bote"; HEXM-0012), así
 * que pasa tal cual, o null si no se indicó.
 *
 * CONCEPTO: DTO de lectura (como Recipes\Application\IngredientView)
 *
 * CAPA: Aplicación (MealPlanning\Application).
 */
final readonly class DishIngredientView
{
    public function __construct(
        public string $name,
        public ?string $quantity,
    ) {}

    public static function fromIngredient(DishIngredient $ingredient): self
    {
        return new self($ingredient->name(), $ingredient->quantity());
    }
}
