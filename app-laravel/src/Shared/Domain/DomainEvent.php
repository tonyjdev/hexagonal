<?php

declare(strict_types=1);

namespace Shared\Domain;

use DateTimeImmutable;

/**
 * DomainEvent: algo que ha pasado en el negocio y que a otros les puede
 * interesar.
 *
 * CONCEPTO: evento de dominio (HEXM-0026, ADR 0018)
 * Un evento es un HECHO: ya ha ocurrido y no se puede discutir ni deshacer.
 * Por eso:
 *   - se nombra en PASADO y con palabras del negocio: RecipeDeleted ("se
 *     ha borrado una receta"), no DeleteRecipe (eso es una orden, un
 *     comando) ni RecipeRowRemoved (eso es un detalle técnico);
 *   - es INMUTABLE: nadie puede cambiar lo que ya pasó;
 *   - lleva lo justo para entenderlo sin preguntar a nadie: qué pasó, a
 *     quién y cuándo (occurredOn).
 *
 * La diferencia con una llamada: quien publica un evento NO sabe quién lo
 * escucha, ni si lo escucha alguien. El recetario dice "he borrado esta
 * receta" y sigue con lo suyo; si a los planes les importa, reaccionan
 * (HEXM-0027). Así el emisor no depende de los receptores.
 *
 * Por qué una interfaz en el shared kernel: el puerto de publicación
 * (Shared\Application\EventBus) y el trait RecordsEvents necesitan un tipo
 * común para "un evento cualquiera", de cualquier contexto. Es una pieza
 * técnica, no un concepto del negocio: encaja en Shared (ver su README).
 *
 * CAPA: Dominio compartido (Shared\Domain). Solo PHP nativo.
 */
interface DomainEvent
{
    /**
     * Cuándo ocurrió. Lo recibe quien crea el evento: el dominio no lee el
     * reloj del sistema (ADR 0012).
     */
    public function occurredOn(): DateTimeImmutable;
}
