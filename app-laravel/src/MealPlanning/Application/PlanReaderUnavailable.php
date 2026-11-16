<?php

declare(strict_types=1);

namespace MealPlanning\Application;

use Shared\Application\ServiceUnavailable;
use Throwable;

/**
 * PlanReaderUnavailable: ahora mismo no se pueden leer documentos de planes.
 *
 * La lanza un adaptador de PlanReader cuando el problema NO es el documento,
 * sino el lector: el servicio no responde, tarda demasiado, falla por dentro
 * (un 5xx) o responde algo que no cumple el contrato.
 *
 * CONCEPTO: traducir errores técnicos a errores propios
 * El adaptador HTTP recibe errores que solo él entiende: una
 * ConnectionException de Laravel, un 500 de FastAPI, un JSON sin un campo.
 * Si los dejara escapar, el caso de uso tendría que conocer HTTP (y el día
 * que el lector sea otro, otros errores). En su lugar, los traduce a esta
 * excepción del puerto, que todos los adaptadores comparten. El error
 * original no se pierde: va como $previous, y es lo que sale en el log.
 *
 * DECISIÓN: un contrato roto también es «no disponible» (ADR 0024)
 * Si el servicio responde 200 con un JSON que no tiene la forma acordada, el
 * usuario no puede hacer nada para arreglarlo: es un error de despliegue
 * (dos versiones que no se entienden). Se le dice lo mismo que con el
 * servicio caído, y el detalle queda en el log, donde lo verá quien pueda
 * arreglarlo. Los tests de contrato (contracts/importer) existen para que
 * esto no llegue a pasar.
 *
 * Hereda de Shared\Application\ServiceUnavailable: HTTP 503, y se registra.
 *
 * CAPA: Aplicación (MealPlanning\Application), junto a su puerto.
 */
final class PlanReaderUnavailable extends ServiceUnavailable
{
    private const string MESSAGE = 'Ahora mismo no se pueden leer los documentos del plan. Inténtalo de nuevo en unos minutos.';

    /**
     * El lector no responde: caído, sin red o sin respuesta a tiempo.
     */
    public static function unreachable(Throwable $cause): self
    {
        return new self(self::MESSAGE, previous: $cause);
    }

    /**
     * El lector responde, pero con un error suyo (un 5xx) o con algo que no
     * cumple el contrato. $cause explica qué ha pasado, para el log.
     */
    public static function unexpectedResponse(Throwable $cause): self
    {
        return new self(self::MESSAGE, previous: $cause);
    }
}
