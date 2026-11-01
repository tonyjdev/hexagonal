<?php

declare(strict_types=1);

namespace Recipes\Application;

use Recipes\Domain\RecipeId;
use Recipes\Domain\RecipeRepository;

/**
 * GetRecipe: caso de uso "consultar una receta del recetario".
 *
 * CONCEPTO: CQS (Command-Query Separation, separación entre comandos y consultas)
 * Un método (o un caso de uso) debe hacer UNA de estas dos cosas:
 *   - un COMANDO cambia el estado del sistema y no devuelve datos (como
 *     mucho, el id de lo creado): CreateRecipe;
 *   - una CONSULTA devuelve datos y NO cambia nada: GetRecipe.
 * Así, llamar a una consulta es siempre seguro: se puede repetir, cachear o
 * hacer en cualquier orden sin efectos secundarios. En HTTP encaja con la
 * semántica de los verbos: POST (comando) frente a GET (consulta).
 *
 * Esto es CQS, no CQRS: comando y consulta comparten el mismo modelo y el
 * mismo puerto (RecipeRepository::find()). CQRS iría más lejos, con un
 * modelo y un almacenamiento propios para leer (por ejemplo, una consulta
 * SQL directa que devuelva la vista sin reconstruir el agregado). Para un
 * recetario personal no compensa: se descarta por ahora.
 *
 * Contrato del caso de uso:
 *   - recibe el id como texto (un primitivo, como CreateRecipeCommand);
 *   - devuelve un RecipeView (DTO de lectura), nunca la entidad Recipe;
 *   - si la receta no existe, lanza RecipeNotFound (→ HTTP 404);
 *   - si el id ni siquiera tiene forma de identificador, el dominio lanza
 *     InvalidRecipeId (→ HTTP 422). DECISIÓN de HEXM-0011: se deja así, sin
 *     convertirlo en "no encontrado", porque distingue "esta receta no puede
 *     existir" de "esta receta no existe", y no requiere código.
 *
 * CAPA: Aplicación (Recipes\Application). Solo depende de su dominio.
 */
final readonly class GetRecipe
{
    public function __construct(private RecipeRepository $recipes) {}

    public function __invoke(string $id): RecipeView
    {
        $recipeId = RecipeId::fromString($id);

        // CONCEPTO: throw como expresión (PHP 8)
        // `?? throw` lanza la excepción solo si find() devuelve null.
        $recipe = $this->recipes->find($recipeId) ?? throw RecipeNotFound::withId($recipeId);

        return RecipeView::fromRecipe($recipe);
    }
}
