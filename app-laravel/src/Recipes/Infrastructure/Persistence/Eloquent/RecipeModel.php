<?php

declare(strict_types=1);

namespace Recipes\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * RecipeModel: una fila de la tabla `recipes`.
 *
 * CONCEPTO: modelo de persistencia frente a modelo de dominio
 * En un Laravel clásico, App\Models\Recipe sería a la vez la tabla y el
 * negocio. Aquí son dos clases distintas:
 *   - Recipes\Domain\Recipe: el agregado, con sus invariantes y sin saber
 *     nada de tablas;
 *   - esta clase: un "DTO con superpoderes" que sabe leer y escribir filas.
 * Nunca sale de Recipes\Infrastructure: ni el caso de uso ni el controlador
 * (HEXM-0011) la verán. Por eso vive aquí y no en app/Models, y por eso se
 * llama *Model: para que no se confunda con la entidad.
 *
 * CONCEPTO: atributos de PHP para configurar Eloquent (Laravel 13)
 * #[Table(...)] sustituye a las propiedades $table, $primaryKey, $keyType e
 * $incrementing. La clave es un uuid que trae el dominio (ADR 0005), no un
 * autoincremental.
 * #[Unguarded]: la protección de asignación masiva ($fillable) sirve para
 * que un Request no rellene columnas que no debe. Aquí los datos solo llegan
 * desde RecipeMapper, que ya viene de un agregado válido.
 *
 * @property string $id
 * @property string $name
 * @property int $servings
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table('recipes', keyType: 'string', incrementing: false)]
#[Unguarded]
final class RecipeModel extends Model
{
    /**
     * Ingredientes en el orden en que se escribieron. El orden se pide aquí,
     * en la relación, para que nadie pueda cargar la receta "desordenada".
     *
     * @return HasMany<RecipeIngredientModel, $this>
     */
    public function ingredients(): HasMany
    {
        return $this->hasMany(RecipeIngredientModel::class, 'recipe_id')->orderBy('position');
    }

    /**
     * @return HasMany<RecipeStepModel, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(RecipeStepModel::class, 'recipe_id')->orderBy('position');
    }

    /**
     * Casts: en PostgreSQL un smallint ya llega como int, pero declararlo deja
     * claro el tipo y no depende del driver.
     */
    protected function casts(): array
    {
        return ['servings' => 'integer'];
    }
}
