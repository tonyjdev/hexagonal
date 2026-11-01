<?php

declare(strict_types=1);

namespace Recipes\Infrastructure;

use Recipes\Domain\Recipe;
use Recipes\Domain\RecipeId;
use Recipes\Domain\RecipeRepository;

/**
 * InMemoryRecipeRepository: guarda las recetas en un array de PHP.
 *
 * CONCEPTO: Adaptador (driven adapter)
 * Implementa el puerto RecipeRepository. Es un adaptador "de verdad", tan
 * válido como EloquentRecipeRepository (HEXM-0009): el caso de uso no nota
 * la diferencia, y los tests de contrato del puerto
 * (tests/Feature/Recipes/Infrastructure/RecipeRepositoryContractTest.php)
 * comprueban que los dos se comportan igual. Vive en Infrastructure, aunque
 * no use Laravel ni base de datos, porque es un DETALLE de cómo se guardan
 * las recetas.
 *
 * CONCEPTO: doble de test en memoria (fake) frente a mock
 * En los tests del caso de uso podríamos usar un MOCK: "espera que se llame a
 * save() una vez con una Recipe". Eso comprueba CÓMO trabaja el caso de uso
 * (sus llamadas), y el test se rompe si se reorganiza el código aunque el
 * resultado sea el mismo. Con este FAKE comprobamos el RESULTADO: después de
 * crear la receta, ¿está guardada y es correcta? Además, el fake se reutiliza
 * en todos los tests de los casos de uso.
 *
 * DECISIÓN: guarda y devuelve COPIAS (clone)
 * Recipe es mutable (rename()). Si guardáramos el mismo objeto, un
 * $recipe->rename('X') sin save() cambiaría también lo "guardado", cosa que
 * con PostgreSQL nunca pasa. Clonando al guardar y al leer, este adaptador se
 * comporta como una base de datos real y los tests no esconden errores (por
 * ejemplo, un caso de uso que olvida llamar a save()).
 * Un clone superficial basta: las propiedades de Recipe son value objects
 * inmutables, arrays (que se copian por valor) y un string.
 *
 * CAPA: Infraestructura (Recipes\Infrastructure).
 */
final class InMemoryRecipeRepository implements RecipeRepository
{
    /**
     * Recetas guardadas, indexadas por el valor de su RecipeId.
     *
     * @var array<string, Recipe>
     */
    private array $recipes = [];

    public function save(Recipe $recipe): void
    {
        $this->recipes[$recipe->id()->value()] = clone $recipe;
    }

    public function find(RecipeId $id): ?Recipe
    {
        $recipe = $this->recipes[$id->value()] ?? null;

        return $recipe === null ? null : clone $recipe;
    }

    /**
     * Cuántas recetas hay guardadas. No forma parte del puerto: es una
     * ayuda para los tests ("no se ha guardado nada").
     */
    public function count(): int
    {
        return count($this->recipes);
    }
}
