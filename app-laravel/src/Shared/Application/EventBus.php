<?php

declare(strict_types=1);

namespace Shared\Application;

use Shared\Domain\DomainEvent;

/**
 * EventBus: el puerto por el que la aplicación anuncia los eventos de dominio.
 *
 * CONCEPTO: puerto de publicación de eventos (HEXM-0026, ADR 0018)
 * El caso de uso dice "esto ha pasado" y el bus se encarga de que llegue a
 * quien le interese. El caso de uso no sabe quién escucha, cuántos son, ni
 * si se entregan en el momento o por una cola: eso lo decide el adaptador.
 *
 *     DeleteRecipe ──publish(RecipeDeleted)──► EventBus ◄── LaravelEventBus (dispatcher de Laravel)
 *                                                       ◄── InMemoryEventBus (tests)
 *
 * Por qué en Shared\Application (como el Clock, ADR 0012): publicar es un
 * efecto hacia fuera, cosa de la aplicación y no del dominio; y es
 * transversal: cualquier contexto publicará sus eventos por el mismo bus.
 *
 * Por qué una interfaz propia y no Illuminate\Contracts\Events\Dispatcher:
 * el núcleo no conoce Laravel. El contrato de Laravel es enorme (listen,
 * until, push, flush...); el núcleo solo necesita publicar.
 *
 * CONTRATO: los eventos se publican DESPUÉS de que la operación que los
 * produce se haya confirmado (ADR 0018). Si la operación está dentro de una
 * transacción, el adaptador espera al COMMIT; si se deshace (ROLLBACK), no
 * se publica nada: nunca se anuncia algo que no ha pasado.
 *
 * CAPA: Aplicación compartida (Shared\Application): puerto de salida.
 */
interface EventBus
{
    /**
     * Publica los eventos en el orden en que se reciben. Sin eventos, no hace
     * nada (así el caso de uso puede pasar lo que le dé releaseEvents()).
     */
    public function publish(DomainEvent ...$events): void;
}
