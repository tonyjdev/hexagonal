<?php

declare(strict_types=1);

namespace Shared\Infrastructure;

use DateTimeZone;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Shared\Application\Clock;
use Shared\Application\EventBus;

/**
 * SharedServiceProvider: la raíz de composición de los puertos compartidos.
 *
 * CONCEPTO: raíz de composición (como RecipesServiceProvider y
 * MealPlanningServiceProvider)
 * Decide qué adaptador hay detrás de cada puerto de Shared. Hoy, dos:
 *   - el reloj: en la aplicación real, Clock es SystemClock en la zona
 *     horaria del usuario (config/clock.php); en los tests, cada test lo
 *     sustituye por un FrozenClock en el contenedor;
 *   - el bus de eventos (HEXM-0026): EventBus es LaravelEventBus, sobre el
 *     dispatcher de Laravel; los tests unitarios usan InMemoryEventBus.
 *
 * DECISIÓN: el reloj se registra aquí y no en MealPlanningServiceProvider
 * (HEXM-0022)
 * Por la misma razón por la que el puerto vive en Shared\Application (ADR
 * 0012): el tiempo no es de ningún contexto. Si lo registrara MealPlanning,
 * el recetario dependería de él para saber qué día es. Así cada contexto
 * usa el reloj sin conocer a los demás.
 *
 * CAPA: Infraestructura compartida (Shared\Infrastructure).
 */
final class SharedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // CONCEPTO: binding con una closure
        // SystemClock necesita un DateTimeZone, que el contenedor no puede
        // adivinar: la closure dice CÓMO construirlo. singleton(): el mismo
        // reloj para toda la petición (no tiene estado, no hace falta uno
        // nuevo cada vez).
        $this->app->singleton(Clock::class, function (Application $app): Clock {
            $timezone = $app->make('config')->get('clock.timezone');

            // Un valor que no es texto en la configuración es un error de
            // despliegue: mejor fallar al arrancar que calcular "hoy" mal.
            if (! is_string($timezone)) {
                throw new \InvalidArgumentException('config/clock.php: "timezone" debe ser un identificador de zona horaria.');
            }

            // new DateTimeZone() lanza una excepción con una zona que no
            // existe ("Europe/Madird"): también falla al arrancar.
            return new SystemClock(new DateTimeZone($timezone));
        });

        // Puerto de publicación de eventos (Shared\Application) → el
        // dispatcher de Laravel (HEXM-0026). El contenedor le pasa el
        // Dispatcher y las conexiones de la base de datos. singleton(): sin
        // estado propio, basta uno.
        $this->app->singleton(EventBus::class, LaravelEventBus::class);
    }
}
