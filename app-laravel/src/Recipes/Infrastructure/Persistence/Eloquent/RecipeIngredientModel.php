<?php

declare(strict_types=1);

namespace Recipes\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * RecipeIngredientModel: una fila de `recipe_ingredients`, es decir, un
 * Ingredient de una receta.
 *
 * CONCEPTO: una fila sin identidad para un value object
 * La tabla no tiene id: su clave primaria es compuesta (recipe_id,
 * position), y Eloquent no sabe trabajar con claves compuestas. Con
 * #[Table(key: false)] le decimos que el modelo NO tiene clave: sirve para
 * LEER filas (a través de RecipeModel::ingredients()), pero no para hacer
 * $model->save() o $model->delete() de una en una. No lo necesitamos:
 * EloquentRecipeRepository reemplaza todos los ingredientes de la receta de
 * golpe, que es justo como se trata un value object (no se "modifica", se
 * sustituye por otro).
 *
 * @property string $recipe_id
 * @property int $position
 * @property string $name
 * @property int|null $numerator
 * @property int|null $denominator
 * @property string|null $unit
 */
#[Table('recipe_ingredients', key: false, incrementing: false, timestamps: false)]
final class RecipeIngredientModel extends Model
{
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'numerator' => 'integer',
            'denominator' => 'integer',
        ];
    }
}
