<?php

declare(strict_types=1);

namespace Recipes\Application;

use Recipes\Domain\InvalidRecipeId;
use Recipes\Domain\RecipeId;
use Recipes\Domain\RecipeRepository;
use Shared\Application\Clock;
use Shared\Application\EventBus;

/**
 * DeleteRecipe: caso de uso "borrar una receta del recetario".
 *
 * CONCEPTO: comando que publica un evento de dominio (HEXM-0026, ADR 0018)
 *
 *     1. find(id) ?? RecipeNotFound        ← "no existe" lo dice el caso de uso
 *     2. $recipe->delete(hoy)              ← el agregado REGISTRA RecipeDeleted
 *     3. RecipeRepository::delete(id)      ← se borra (y se confirma)
 *     4. EventBus::publish(eventos)        ← y solo entonces se ANUNCIA
 *
 * El caso de uso no sabe quién escucha RecipeDeleted (los planes, en
 * HEXM-0027): solo lo publica. Así el recetario no depende de nadie.
 *
 * DECISIÓN: publicar DESPUÉS de borrar, nunca antes
 * Si se publicara antes y el borrado fallara, los demás reaccionarían a una
 * receta que sigue existiendo: se anunciaría algo que no ha pasado. Al
 * publicar después, el riesgo es el contrario: si el proceso se cae justo
 * entre el borrado y la publicación, el aviso se pierde. Es el riesgo más
 * tolerable (un plan apuntando a una receta borrada ya está previsto, ver
 * RecipeReference), y el remedio completo, el patrón outbox (guardar el
 * evento en la misma transacción y publicarlo después), queda fuera de
 * alcance. Si este caso de uso se ejecuta dentro de una transacción más
 * grande, el adaptador del bus espera al COMMIT (ver LaravelEventBus).
 *
 * DECISIÓN: el id mal formado, como en GetRecipe
 * RecipeId::fromString() lanza InvalidRecipeId (→ 422): "esta receta no
 * puede existir" es distinto de "esta receta no existe" (→ 404).
 *
 * CAPA: Aplicación (Recipes\Application).
 */
final readonly class DeleteRecipe
{
    public function __construct(
        private RecipeRepository $recipes,
        private Clock $clock,
        private EventBus $events,
    ) {}

    /**
     * @throws InvalidRecipeId si el id no es un identificador válido
     * @throws RecipeNotFound si no existe ninguna receta con ese id
     */
    public function __invoke(string $id): void
    {
        $recipeId = RecipeId::fromString($id);

        // Si no existe, se para aquí: no se borra ni se publica nada.
        $recipe = $this->recipes->find($recipeId) ?? throw RecipeNotFound::withId($recipeId);

        $recipe->delete($this->clock->today());
        $this->recipes->delete($recipe->id());

        // CONCEPTO: argument unpacking (...) de PHP
        // releaseEvents() devuelve una lista y publish() recibe los eventos
        // sueltos (variádico): el operador ... "despliega" la lista.
        $this->events->publish(...$recipe->releaseEvents());
    }
}
