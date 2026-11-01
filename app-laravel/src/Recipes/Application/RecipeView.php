<?php

declare(strict_types=1);

namespace Recipes\Application;

use Recipes\Domain\Recipe;
use Recipes\Domain\Step;

/**
 * RecipeView: una receta lista para mostrar. Es lo que devuelve GetRecipe.
 *
 * CONCEPTO: DTO de lectura (read model sencillo)
 * GetRecipe NO devuelve la entidad Recipe hacia fuera, por dos motivos:
 *
 *   1. Proteger el agregado. Recipe tiene comportamiento (rename()...). Si
 *      llegara al controlador, cualquier adaptador podría cambiarla sin pasar
 *      por un caso de uso, y nadie la guardaría: un cambio a medias.
 *   2. No acoplar el exterior al modelo interno. Si mañana Recipe cambia su
 *      estructura (por ejemplo, agrupa los ingredientes), los adaptadores
 *      siguen leyendo esta vista sin enterarse.
 *
 * Por eso aquí solo hay PRIMITIVOS ya calculados (strings, ints, listas) y
 * ningún objeto del dominio: es una foto de solo lectura de la receta,
 * pensada para quien la va a mostrar.
 *
 * Es la contrapartida de CreateRecipeCommand: el comando trae primitivos
 * HACIA el núcleo; la vista saca primitivos DESDE el núcleo.
 *
 * Lo que NO hace: decidir el formato JSON. Los nombres de las claves, si la
 * respuesta va envuelta en "data"... es cosa del adaptador HTTP
 * (RecipeResource). Esta vista también la podría usar un comando de Artisan.
 *
 * CAPA: Aplicación (Recipes\Application). Conoce el dominio para construirse,
 * pero no expone ningún objeto del dominio.
 */
final readonly class RecipeView
{
    /**
     * @param  list<IngredientView>  $ingredients
     * @param  list<string>  $steps  en orden de preparación
     */
    public function __construct(
        public string $id,
        public string $name,
        public int $servings,
        public array $ingredients,
        public array $steps,
    ) {}

    /**
     * CONCEPTO: constructor con nombre para el mapeo dominio → vista
     * La traducción vive junto a la vista que produce: si la vista cambia, se
     * toca un solo fichero, y GetRecipe se queda en orquestar.
     */
    public static function fromRecipe(Recipe $recipe): self
    {
        return new self(
            id: $recipe->id()->value(),
            name: $recipe->name(),
            servings: $recipe->servings()->value(),
            ingredients: array_map(IngredientView::fromIngredient(...), $recipe->ingredients()),
            steps: array_map(fn (Step $step): string => $step->text(), $recipe->steps()),
        );
    }
}
