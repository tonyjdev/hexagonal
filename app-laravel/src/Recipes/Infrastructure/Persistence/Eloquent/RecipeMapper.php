<?php

declare(strict_types=1);

namespace Recipes\Infrastructure\Persistence\Eloquent;

use Recipes\Domain\Ingredient;
use Recipes\Domain\Quantity;
use Recipes\Domain\Recipe;
use Recipes\Domain\RecipeId;
use Recipes\Domain\Servings;
use Recipes\Domain\Step;
use Recipes\Domain\Unit;
use UnexpectedValueException;

/**
 * RecipeMapper: traduce entre el agregado Recipe y sus filas en PostgreSQL.
 *
 * CONCEPTO: Data Mapper (mapper de persistencia)
 * Eloquent es un ACTIVE RECORD: el objeto es la fila y sabe guardarse solo
 * ($model->save()). El dominio, en cambio, no sabe que existe una base de
 * datos. Entre los dos hace falta un traductor que conozca a ambos y que
 * ninguno de los dos conozca a él: este mapper. Así el modelo de dominio
 * (pensado para las reglas de negocio) y el modelo de datos (pensado para
 * tablas, claves y columnas) pueden ser distintos: un Ingredient con
 * Quantity se convierte en una fila con numerator / denominator / unit, y
 * la posición de cada fila sale del orden de la lista.
 *
 * CONCEPTO: reconstitución de un agregado
 * Cargar una receta guardada no es "crear una receta nueva" en el sentido de
 * negocio, pero aquí se reconstruye con los MISMOS constructores con nombre
 * que usa el caso de uso: Recipe::create(), Ingredient::of(),
 * Quantity::fraction(), Step::fromString()... Es decir, las invariantes se
 * vuelven a comprobar al cargar.
 *
 * DECISIÓN: reconstruir con Recipe::create() y no con un reconstitute()
 * La alternativa habitual es un constructor de reconstitución que se salte
 * las validaciones ("lo que hay en la base de datos ya es válido"). Se ha
 * descartado, de momento, porque:
 *   - validar una decena de ingredientes no cuesta nada;
 *   - si la base de datos tiene algo que el dominio no admite (un UPDATE a
 *     mano, una migración mal hecha), es mejor que falle al cargar que dejar
 *     circular una receta inválida por la aplicación;
 *   - no hace falta tocar el dominio (criterio de aceptación de HEXM-0009).
 * Se revisará cuando create() haga algo que NO deba repetirse al cargar, por
 * ejemplo registrar un evento RecipeCreated: entonces sí hará falta
 * Recipe::reconstitute(). Ver docs/adr/0006-persistencia-del-agregado-recipe.md.
 *
 * CAPA: Infraestructura (Recipes\Infrastructure). Puede conocer el dominio y
 * Eloquent; el dominio no lo conoce a él.
 */
final class RecipeMapper
{
    /**
     * Filas → agregado. Espera las relaciones ingredients y steps cargadas
     * (las carga el repositorio con with(), en una consulta por tabla).
     */
    public function toDomain(RecipeModel $model): Recipe
    {
        return Recipe::create(
            RecipeId::fromString($model->id),
            $model->name,
            Servings::of($model->servings),
            // Las relaciones ya vienen ordenadas por position (ver
            // RecipeModel): el orden de la lista ES el orden de la receta.
            $model->ingredients->map(fn (RecipeIngredientModel $row): Ingredient => $this->ingredientToDomain($row))->all(),
            $model->steps->map(fn (RecipeStepModel $row): Step => Step::fromString($row->text))->all(),
        );
    }

    /**
     * Agregado → columnas de la tabla recipes.
     *
     * @return array{id: string, name: string, servings: int}
     */
    public function toRecipeRow(Recipe $recipe): array
    {
        return [
            'id' => $recipe->id()->value(),
            'name' => $recipe->name(),
            'servings' => $recipe->servings()->value(),
        ];
    }

    /**
     * Agregado → filas de recipe_ingredients. La posición es el índice de la
     * lista (0, 1, 2...), que es como el dominio guarda el orden.
     *
     * @return list<array{recipe_id: string, position: int, name: string, numerator: int|null, denominator: int|null, unit: string|null}>
     */
    public function toIngredientRows(Recipe $recipe): array
    {
        $rows = [];

        foreach ($recipe->ingredients() as $position => $ingredient) {
            $quantity = $ingredient->quantity();

            $rows[] = [
                'recipe_id' => $recipe->id()->value(),
                'position' => $position,
                'name' => $ingredient->name(),
                // "Al gusto" (quantity null) → las tres columnas a NULL.
                // El operador ?-> (nullsafe) devuelve null si $quantity lo es.
                'numerator' => $quantity?->numerator(),
                'denominator' => $quantity?->denominator(),
                'unit' => $quantity?->unit()->value,
            ];
        }

        return $rows;
    }

    /**
     * Agregado → filas de recipe_steps.
     *
     * @return list<array{recipe_id: string, position: int, text: string}>
     */
    public function toStepRows(Recipe $recipe): array
    {
        $rows = [];

        foreach ($recipe->steps() as $position => $step) {
            $rows[] = [
                'recipe_id' => $recipe->id()->value(),
                'position' => $position,
                'text' => $step->text(),
            ];
        }

        return $rows;
    }

    private function ingredientToDomain(RecipeIngredientModel $row): Ingredient
    {
        if ($row->numerator === null && $row->denominator === null && $row->unit === null) {
            return Ingredient::toTaste($row->name);
        }

        if ($row->numerator === null || $row->denominator === null || $row->unit === null) {
            // No debería pasar nunca: el CHECK de la tabla lo impide. Si pasa,
            // los datos están corruptos y no hay forma correcta de leerlos.
            throw new UnexpectedValueException(
                "La cantidad del ingrediente «{$row->name}» de la receta {$row->recipe_id} está incompleta.",
            );
        }

        // Quantity::fraction() simplifica la fracción, pero ya se guardó
        // simplificada: 1/2 sigue siendo 1/2. Unit::from() falla si el valor
        // no es una unidad conocida (de nuevo, datos corruptos).
        return Ingredient::of(
            $row->name,
            Quantity::fraction($row->numerator, $row->denominator, Unit::from($row->unit)),
        );
    }
}
