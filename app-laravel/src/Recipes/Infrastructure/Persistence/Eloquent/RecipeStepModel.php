<?php

declare(strict_types=1);

namespace Recipes\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * RecipeStepModel: una fila de `recipe_steps`, es decir, un Step de una
 * receta.
 *
 * Igual que RecipeIngredientModel: sin clave propia (la de la tabla es
 * compuesta, (recipe_id, position)), solo para leer. Ver allí el porqué.
 *
 * @property string $recipe_id
 * @property int $position
 * @property string $text
 */
#[Table('recipe_steps', key: false, incrementing: false, timestamps: false)]
final class RecipeStepModel extends Model
{
    protected function casts(): array
    {
        return ['position' => 'integer'];
    }
}
