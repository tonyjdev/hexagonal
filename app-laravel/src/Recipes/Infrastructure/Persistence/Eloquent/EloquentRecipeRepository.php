<?php

declare(strict_types=1);

namespace Recipes\Infrastructure\Persistence\Eloquent;

use Recipes\Domain\Recipe;
use Recipes\Domain\RecipeId;
use Recipes\Domain\RecipeRepository;

/**
 * EloquentRecipeRepository: guarda las recetas en PostgreSQL usando Eloquent.
 *
 * CONCEPTO: segundo adaptador para el mismo puerto
 * Implementa RecipeRepository, igual que InMemoryRecipeRepository. El caso de
 * uso CreateRecipe no cambia ni una línea: recibe "un RecipeRepository" y le
 * da igual cuál. Esta es la promesa de la arquitectura hexagonal: la base de
 * datos es un DETALLE que se enchufa desde fuera. Que los dos adaptadores se
 * comporten igual lo garantizan los tests de contrato
 * (tests/Feature/Recipes/Infrastructure/RecipeRepositoryContractTest.php).
 *
 * CONCEPTO: el repositorio guarda AGREGADOS, no filas
 * save() recibe la receta entera y la deja en la base de datos tal cual, en
 * sus tres tablas. Nadie de fuera inserta "un ingrediente": si una receta
 * cambia sus ingredientes, se guarda la receta.
 *
 * CONCEPTO: transacción por agregado
 * Una receta son varias filas en tres tablas. Si se guardara la cabecera y
 * fallara la inserción de los pasos, quedaría en la base de datos una receta
 * a medias, que ya no cumpliría las invariantes. Por eso save() es TODO O
 * NADA: una transacción por agregado. Es la traducción a la base de datos de
 * "el agregado es la frontera de consistencia".
 *
 * DECISIÓN: reemplazo completo de ingredientes y pasos
 * Al guardar, se borran los ingredientes y pasos anteriores y se insertan
 * los actuales. La alternativa (comparar fila a fila y hacer UPDATE solo de
 * lo que cambió) es más compleja y no aporta nada: son value objects sin
 * identidad, no hay "este ingrediente de antes" que conservar. Con una
 * decena de filas por receta, el coste es despreciable.
 *
 * Límite conocido: si dos personas guardan la misma receta a la vez, gana la
 * última (no hay bloqueo optimista con un número de versión). Para un
 * recetario personal no es un problema real.
 *
 * CAPA: Infraestructura (Recipes\Infrastructure).
 */
final readonly class EloquentRecipeRepository implements RecipeRepository
{
    /**
     * CONCEPTO: new en el valor por defecto de un parámetro (PHP 8.1)
     * El mapper no tiene dependencias ni configuración, así que no hace
     * falta registrarlo en el contenedor: si nadie pasa otro, se crea uno.
     */
    public function __construct(private RecipeMapper $mapper = new RecipeMapper) {}

    public function save(Recipe $recipe): void
    {
        $id = $recipe->id()->value();

        // La transacción se abre en la conexión DEL MODELO, no en la conexión
        // por defecto (DB::transaction). Así, si algún día el recetario se
        // guarda en otra conexión, la transacción y las consultas no pueden
        // ir por caminos distintos.
        //
        // transaction() hace COMMIT si la función termina y ROLLBACK si lanza
        // una excepción (que se vuelve a lanzar: el que llama se entera).
        (new RecipeModel)->getConnection()->transaction(function () use ($recipe, $id): void {
            // Alta o modificación de la raíz: UPDATE si ya existe una fila
            // con ese id, INSERT si no. Eloquent rellena created_at /
            // updated_at.
            RecipeModel::query()->updateOrCreate(['id' => $id], $this->mapper->toRecipeRow($recipe));

            // Reemplazo completo de las partes del agregado (ver arriba).
            RecipeIngredientModel::query()->where('recipe_id', $id)->delete();
            RecipeStepModel::query()->where('recipe_id', $id)->delete();

            // insert() con una lista de filas: un único INSERT con varios
            // VALUES, en vez de un INSERT por ingrediente. Se usa la consulta
            // y no $model->save() porque estos modelos no tienen clave propia.
            RecipeIngredientModel::query()->insert($this->mapper->toIngredientRows($recipe));

            $steps = $this->mapper->toStepRows($recipe);

            // Los pasos son opcionales; insert([]) no hace nada, pero así se
            // ve que la receta puede no tenerlos.
            if ($steps !== []) {
                RecipeStepModel::query()->insert($steps);
            }
        });
    }

    public function find(RecipeId $id): ?Recipe
    {
        // with(): carga ansiosa (eager loading). Tres consultas en total
        // (receta, ingredientes, pasos) en vez de una por relación accedida.
        $model = RecipeModel::query()
            ->with(['ingredients', 'steps'])
            ->whereKey($id->value())
            ->first();

        return $model === null ? null : $this->mapper->toDomain($model);
    }
}
