<?php

declare(strict_types=1);

namespace Shared\Infrastructure;

use Shared\Application\EventBus;
use Shared\Domain\DomainEvent;

/**
 * InMemoryEventBus: un bus que no entrega nada a nadie, solo apunta lo que
 * se publica.
 *
 * CONCEPTO: doble de test de un puerto (como FrozenClock para el Clock)
 * Con él, un caso de uso se prueba sin Laravel y se puede comprobar qué
 * eventos ha publicado y cuántos: "al borrar se publica exactamente un
 * RecipeDeleted", "si la receta no existe, no se publica nada".
 *
 * Vive en Infrastructure aunque no use Laravel: es un adaptador más del
 * puerto, como InMemoryRecipeRepository.
 *
 * CAPA: Infraestructura compartida (Shared\Infrastructure).
 */
final class InMemoryEventBus implements EventBus
{
    /** @var list<DomainEvent> */
    private array $published = [];

    public function publish(DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            $this->published[] = $event;
        }
    }

    /**
     * Lo publicado hasta ahora, en orden. No forma parte del puerto: es una
     * ayuda para los tests.
     *
     * @return list<DomainEvent>
     */
    public function published(): array
    {
        return $this->published;
    }
}
