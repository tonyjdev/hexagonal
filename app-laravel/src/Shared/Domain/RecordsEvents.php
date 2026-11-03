<?php

declare(strict_types=1);

namespace Shared\Domain;

/**
 * RecordsEvents: lo que necesita un agregado para anotar los eventos que le
 * pasan, hasta que alguien los publique.
 *
 * CONCEPTO: el agregado registra sus eventos; la aplicación los publica
 * (HEXM-0026, ADR 0018)
 *
 *     $recipe->delete($today);                  // dominio: registra RecipeDeleted
 *     $recipes->delete($recipe->id());          // persistencia
 *     $events->publish(...$recipe->releaseEvents());  // aplicación: publica
 *
 * El agregado es quien sabe qué le ha pasado, así que es quien crea el
 * evento: ningún caso de uso que borre una receta puede "olvidarse" de él.
 * Pero el dominio no publica nada: no conoce el puerto EventBus (está en
 * Application) ni debe hacer efectos hacia fuera a mitad de una operación.
 * Solo los ANOTA; el caso de uso los recoge con releaseEvents() cuando la
 * operación ha terminado y decide cuándo publicarlos (después de guardar).
 *
 * CONCEPTO: trait de PHP
 * Un trait es un trozo de código (propiedades y métodos) que se "copia"
 * dentro de la clase que lo usa (`use RecordsEvents;`). Se elige frente a
 * una clase base abstracta (AggregateRoot) porque PHP solo permite heredar
 * de una clase, y porque así cada agregado decide si registra eventos sin
 * cambiar su jerarquía. En Laravel lo ves en los modelos (`use HasFactory`).
 *
 * CAPA: Dominio compartido (Shared\Domain). Solo PHP nativo.
 */
trait RecordsEvents
{
    /**
     * Eventos registrados y aún no publicados, en el orden en que pasaron.
     *
     * @var list<DomainEvent>
     */
    private array $recordedEvents = [];

    /**
     * Anota un evento. Protegido: solo el propio agregado decide qué le ha
     * pasado; nadie de fuera puede "inventarle" un evento.
     */
    protected function record(DomainEvent $event): void
    {
        $this->recordedEvents[] = $event;
    }

    /**
     * Entrega los eventos registrados y los olvida, para que no se publiquen
     * dos veces si alguien vuelve a llamar.
     *
     * @return list<DomainEvent>
     */
    public function releaseEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }
}
