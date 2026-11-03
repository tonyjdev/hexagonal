<?php

/*
| Tests del caso de uso DeleteRecipe (HEXM-0026).
|
| Sin Laravel ni PostgreSQL: el repositorio en memoria (que cumple el mismo
| contrato que el de Eloquent), un reloj fijo (FrozenClock) y un bus en
| memoria que apunta lo publicado. Comprueban que:
|   - al borrar se publica EXACTAMENTE un RecipeDeleted, con el id y el día;
|   - se publica DESPUÉS de borrar (nunca se anuncia algo que no ha pasado);
|   - si la receta no existe o el id está mal formado, no se publica nada.
*/

use Recipes\Application\DeleteRecipe;
use Recipes\Application\RecipeNotFound;
use Recipes\Domain\InvalidRecipeId;
use Recipes\Domain\RecipeDeleted;
use Recipes\Domain\RecipeId;
use Recipes\Infrastructure\InMemoryRecipeRepository;
use Shared\Application\EventBus;
use Shared\Domain\DomainEvent;
use Shared\Infrastructure\FrozenClock;
use Shared\Infrastructure\InMemoryEventBus;
use Tests\Support\Recipes\RecipeMother;

/**
 * El caso de uso con sus dobles: hoy es el martes 03/11/2026.
 */
function deleteRecipe(InMemoryRecipeRepository $recipes, EventBus $events): DeleteRecipe
{
    return new DeleteRecipe($recipes, FrozenClock::on('2026-11-03'), $events);
}

it('deletes the recipe and publishes exactly one RecipeDeleted', function () {
    $recipes = new InMemoryRecipeRepository;
    $events = new InMemoryEventBus;
    $lentils = RecipeMother::lentilsWithPisto();
    $recipes->save($lentils);

    deleteRecipe($recipes, $events)($lentils->id()->value());

    expect($recipes->find($lentils->id()))->toBeNull()
        ->and($events->published())->toHaveCount(1)
        ->and($events->published()[0])->toBeInstanceOf(RecipeDeleted::class);

    /** @var RecipeDeleted $event */
    $event = $events->published()[0];

    // El id de la receta borrada y el día según el Clock (no el del sistema).
    expect($event->recipeId())->toBe($lentils->id()->value())
        ->and($event->occurredOn()->format('Y-m-d'))->toBe('2026-11-03');
});

it('publishes the event only after the recipe has been deleted', function () {
    // Un bus espía: cuando le llega el evento, mira si la receta sigue
    // guardada. Si se publicara antes de borrar, la encontraría.
    $recipes = new InMemoryRecipeRepository;
    $lentils = RecipeMother::lentilsWithPisto();
    $recipes->save($lentils);

    $spy = new class($recipes, $lentils->id()) implements EventBus
    {
        public ?bool $stillStoredWhenPublished = null;

        public function __construct(private InMemoryRecipeRepository $recipes, private RecipeId $id) {}

        public function publish(DomainEvent ...$events): void
        {
            $this->stillStoredWhenPublished = $this->recipes->find($this->id) !== null;
        }
    };

    deleteRecipe($recipes, $spy)($lentils->id()->value());

    expect($spy->stillStoredWhenPublished)->toBeFalse();
});

it('deletes only that recipe', function () {
    $recipes = new InMemoryRecipeRepository;
    $lentils = RecipeMother::lentilsWithPisto();
    $salad = RecipeMother::chickpeaSalad();
    $recipes->save($lentils);
    $recipes->save($salad);

    deleteRecipe($recipes, new InMemoryEventBus)($lentils->id()->value());

    expect($recipes->count())->toBe(1)
        ->and($recipes->find($salad->id()))->not->toBeNull();
});

it('throws RecipeNotFound and publishes nothing when the recipe does not exist', function () {
    $recipes = new InMemoryRecipeRepository;
    $events = new InMemoryEventBus;
    $salad = RecipeMother::chickpeaSalad();
    $recipes->save($salad);
    $missing = RecipeId::generate();

    expect(fn () => deleteRecipe($recipes, $events)($missing->value()))
        ->toThrow(RecipeNotFound::class, "No existe ninguna receta con el identificador «{$missing->value()}».")
        ->and($events->published())->toBe([])
        ->and($recipes->count())->toBe(1);
});

it('rejects a malformed id and publishes nothing', function () {
    // "No puede existir" (422) no es lo mismo que "no existe" (404).
    $events = new InMemoryEventBus;

    expect(fn () => deleteRecipe(new InMemoryRecipeRepository, $events)('guiso-de-alubias'))
        ->toThrow(InvalidRecipeId::class)
        ->and($events->published())->toBe([]);
});

it('cannot delete the same recipe twice', function () {
    // La segunda vez ya no existe: 404 y ningún segundo evento.
    $recipes = new InMemoryRecipeRepository;
    $events = new InMemoryEventBus;
    $salad = RecipeMother::chickpeaSalad();
    $recipes->save($salad);
    deleteRecipe($recipes, $events)($salad->id()->value());

    expect(fn () => deleteRecipe($recipes, $events)($salad->id()->value()))->toThrow(RecipeNotFound::class)
        ->and($events->published())->toHaveCount(1);
});
