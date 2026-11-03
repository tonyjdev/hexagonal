<?php

declare(strict_types=1);

namespace MealPlanning\Application;

use MealPlanning\Domain\Dish;

/**
 * DishView: un plato (la comida o la cena de un día), listo para mostrar.
 *
 * Refleja los dos formatos de Dish (HEXM-0012) sin esconderlos:
 *   - semana 1, de la nutricionista: solo `description`, en texto libre
 *     ("Lentejas (½ bote) con pisto…");
 *   - semana 2, del usuario: `name` corto ("Pasta con salmón y pesto") y,
 *     opcionalmente, sus ingredientes.
 * Quien lo muestre decide cómo pintar cada caso. Siempre hay al menos uno de
 * los dos textos, porque un plato nunca está vacío (regla 13).
 *
 * CONCEPTO: DTO de lectura (como Recipes\Application\RecipeView)
 *
 * CAPA: Aplicación (MealPlanning\Application).
 */
final readonly class DishView
{
    /**
     * @param  list<DishIngredientView>  $ingredients
     */
    public function __construct(
        public ?string $name,
        public ?string $description,
        public array $ingredients,
    ) {}

    public static function fromDish(Dish $dish): self
    {
        return new self(
            name: $dish->name(),
            description: $dish->description(),
            ingredients: array_map(DishIngredientView::fromIngredient(...), $dish->ingredients()),
        );
    }
}
