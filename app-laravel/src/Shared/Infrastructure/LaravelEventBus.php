<?php

declare(strict_types=1);

namespace Shared\Infrastructure;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Shared\Application\EventBus;
use Shared\Domain\DomainEvent;

/**
 * LaravelEventBus: el puerto EventBus sobre el sistema de eventos de Laravel.
 *
 * CONCEPTO: adaptador de un puerto propio sobre una pieza del framework
 * (HEXM-0026, ADR 0018)
 * El núcleo publica con su interfaz (EventBus::publish()); aquí se traduce a
 * Dispatcher::dispatch(), el mismo que usa event() en un Laravel clásico.
 * Así se aprovecha todo lo que Laravel ya trae para ESCUCHAR: Event::listen(),
 * listeners con inyección de dependencias, descubrimiento automático... Los
 * oyentes de HEXM-0027 serán listeners normales de Laravel, y el evento que
 * reciben es el objeto del dominio tal cual (RecipeDeleted).
 *
 * CONCEPTO: publicar después del COMMIT (afterCommit)
 * Si la publicación ocurre dentro de una transacción de base de datos, se
 * aplaza hasta que esa transacción se confirme, y se descarta si se deshace.
 * Sin transacción abierta (el caso normal: DeleteRecipe borra con una sola
 * sentencia), se publica en el momento. Es el contrato del puerto: nunca se
 * anuncia algo que no ha pasado. Laravel lo resuelve con
 * Connection::afterCommit(), que en los tests con RefreshDatabase sabe
 * ignorar la transacción que envuelve cada test.
 *
 * En un Laravel clásico se conseguiría marcando el evento con la interfaz
 * ShouldDispatchAfterCommit; aquí no se puede: el evento es del dominio y no
 * puede implementar una interfaz de Laravel. Por eso lo decide el adaptador.
 *
 * Límite conocido: espera a la transacción de la conexión POR DEFECTO, que
 * es la de todos los repositorios del proyecto. Si un día un contexto usa
 * otra conexión, habrá que revisarlo.
 *
 * CAPA: Infraestructura compartida (Shared\Infrastructure).
 */
final readonly class LaravelEventBus implements EventBus
{
    public function __construct(
        private Dispatcher $dispatcher,
        private ConnectionResolverInterface $database,
    ) {}

    public function publish(DomainEvent ...$events): void
    {
        if ($events === []) {
            return;
        }

        $this->afterCommit(function () use ($events): void {
            foreach ($events as $event) {
                $this->dispatcher->dispatch($event);
            }
        });
    }

    /**
     * Ejecuta la función cuando se confirme la transacción en curso, o ya
     * mismo si no hay ninguna.
     *
     * El instanceof hace falta porque connection() devuelve el CONTRATO
     * ConnectionInterface, que no declara afterCommit(): lo tiene la clase
     * Connection (todas las conexiones reales de Laravel la heredan). Si
     * llegara otra cosa, no hay transacción que esperar.
     */
    private function afterCommit(\Closure $callback): void
    {
        $connection = $this->database->connection();

        if ($connection instanceof Connection) {
            $connection->afterCommit($callback);

            return;
        }

        $callback();
    }
}
