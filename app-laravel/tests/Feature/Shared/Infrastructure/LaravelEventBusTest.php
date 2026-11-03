<?php

/*
| Tests del adaptador LaravelEventBus (HEXM-0026).
|
| Comprueban lo que el bus en memoria no puede ver:
|   - que los eventos llegan a los oyentes normales de Laravel (Event::listen);
|   - el contrato del puerto: dentro de una transacción, se publica DESPUÉS
|     del COMMIT, y con un ROLLBACK no se publica nada;
|   - que en la aplicación el puerto está enchufado a este adaptador.
|
| Con RefreshDatabase cada test va dentro de una transacción; Laravel la
| ignora para afterCommit(), así que DB::transaction() de aquí se comporta
| como en producción.
*/

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Recipes\Domain\RecipeDeleted;
use Recipes\Domain\RecipeId;
use Shared\Application\EventBus;
use Shared\Infrastructure\LaravelEventBus;

uses(RefreshDatabase::class);

/**
 * Un RecipeDeleted de ejemplo.
 */
function aRecipeDeleted(): RecipeDeleted
{
    return RecipeDeleted::of(RecipeId::generate(), new DateTimeImmutable('2026-11-03'));
}

/**
 * Apunta en $log cada RecipeDeleted que reciba un oyente de Laravel.
 *
 * @param  list<string>  $log
 */
function listenToRecipeDeleted(array &$log): void
{
    Event::listen(RecipeDeleted::class, function (RecipeDeleted $event) use (&$log): void {
        $log[] = $event->recipeId();
    });
}

it('binds the event bus port to the Laravel adapter', function () {
    expect(app(EventBus::class))->toBeInstanceOf(LaravelEventBus::class);
});

it('delivers the domain events to the Laravel listeners, in order', function () {
    $log = [];
    listenToRecipeDeleted($log);
    $first = aRecipeDeleted();
    $second = aRecipeDeleted();

    app(EventBus::class)->publish($first, $second);

    expect($log)->toBe([$first->recipeId(), $second->recipeId()]);
});

it('publishes inside a transaction only after the commit', function () {
    $log = [];
    listenToRecipeDeleted($log);
    $seenInsideTheTransaction = null;

    DB::transaction(function () use (&$log, &$seenInsideTheTransaction): void {
        app(EventBus::class)->publish(aRecipeDeleted());
        // Todavía dentro: nadie se ha enterado.
        $seenInsideTheTransaction = count($log);
    });

    expect($seenInsideTheTransaction)->toBe(0)
        ->and($log)->toHaveCount(1);
});

it('publishes nothing if the transaction is rolled back', function () {
    // Nunca se anuncia algo que no ha pasado.
    $log = [];
    listenToRecipeDeleted($log);

    try {
        DB::transaction(function (): void {
            app(EventBus::class)->publish(aRecipeDeleted());

            throw new RuntimeException('el borrado falla después de publicar');
        });
    } catch (RuntimeException) {
        // Esperado: la transacción se deshace.
    }

    expect($log)->toBe([]);
});
