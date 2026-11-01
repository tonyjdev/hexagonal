<?php

declare(strict_types=1);

namespace Recipes\Infrastructure\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Recipes\Application\IngredientView;
use Recipes\Application\RecipeView;

/**
 * RecipeResource: el JSON de una receta en la API.
 *
 * CONCEPTO: el formato de salida es responsabilidad del adaptador
 * El caso de uso devuelve un RecipeView (datos ya calculados, sin formato).
 * Cómo se presentan esos datos en HTTP (los nombres de las claves en
 * snake_case, el envoltorio "data", qué campos se exponen) es una decisión
 * de la API, no del núcleo, y por eso vive aquí, en Infrastructure\Http. Si
 * mañana hay una segunda versión de la API, o un comando de Artisan que
 * imprime la receta, cada uno formatea la MISMA vista a su manera.
 *
 * CONCEPTO: API Resource de Laravel
 * Normalmente envuelve un modelo de Eloquent (`new UserResource($user)`).
 * Aquí envuelve un DTO de la aplicación: a JsonResource le da igual el tipo
 * de objeto, solo le pide que toArray() diga cómo convertirlo. Laravel envuelve
 * la respuesta en {"data": {...}} por defecto, la convención de los API
 * Resources (deja sitio a metadatos como la paginación de un futuro listado).
 *
 * Se eligió frente a que RecipeView implementara JsonSerializable
 * (decisión de HEXM-0011): así el contrato JSON no queda definido en la capa
 * de aplicación.
 *
 * Contrato de la respuesta (GET /api/recipes/{id}):
 *
 *   {"data": {
 *     "id": "01a1...", "name": "Pisto", "servings": 2,
 *     "ingredients": [
 *       {"name": "Tomate triturado", "amount": "½", "unit": "jar", "unit_label": "bote", "to_taste": false},
 *       {"name": "Sal", "amount": null, "unit": null, "unit_label": null, "to_taste": true}
 *     ],
 *     "steps": ["Picar las verduras", "Pochar a fuego lento"]
 *   }}
 *
 * amount y unit tienen la misma forma que en POST /api/recipes: un cliente
 * puede leer una receta y volver a enviarla.
 *
 * CAPA: Infraestructura, adaptador de entrada HTTP (Recipes\Infrastructure\Http).
 */
final class RecipeResource extends JsonResource
{
    /**
     * El constructor tipado deja claro (a quien lee y a PHPStan) que este
     * resource solo envuelve un RecipeView.
     */
    public function __construct(RecipeView $recipe)
    {
        parent::__construct($recipe);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // JsonResource guarda el objeto en $this->resource (tipo mixed): se
        // comprueba para que PHPStan sepa que es la vista.
        $recipe = $this->resource;
        assert($recipe instanceof RecipeView);

        return [
            'id' => $recipe->id,
            'name' => $recipe->name,
            'servings' => $recipe->servings,
            'ingredients' => array_map(
                fn (IngredientView $ingredient): array => [
                    'name' => $ingredient->name,
                    'amount' => $ingredient->amount,
                    'unit' => $ingredient->unit,
                    'unit_label' => $ingredient->unitLabel,
                    'to_taste' => $ingredient->toTaste,
                ],
                $recipe->ingredients,
            ),
            'steps' => $recipe->steps,
        ];
    }
}
