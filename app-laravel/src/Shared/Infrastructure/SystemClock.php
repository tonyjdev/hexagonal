<?php

declare(strict_types=1);

namespace Shared\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Shared\Application\Clock;

/**
 * SystemClock: el reloj real. Hoy es el día que marca el reloj del sistema
 * en la zona horaria del usuario.
 *
 * CONCEPTO: adaptador de salida (driven) de un puerto técnico
 * Es el único sitio del código propio donde se lee la hora del sistema
 * ('today'). Está en Infrastructure porque depende del mundo exterior: el
 * reloj de la máquina. El núcleo no lo puede hacer (lo comprueba un test de
 * arquitectura) y lo pide a través del puerto Clock.
 *
 * DECISIÓN: la zona horaria se inyecta (HEXM-0019, ADR 0012)
 * "Hoy" depende de dónde estés: a las 00:30 del día 11 en Madrid, en UTC aún
 * es el día 10. Laravel usa UTC por defecto (config/app.php), así que no se
 * puede confiar en la zona por defecto de PHP. Por eso la zona es un
 * argumento del constructor: la decide la raíz de composición (el service
 * provider, en el nivel 5, leyendo la configuración) y no queda escrita a
 * fuego aquí. Hoy, la del usuario es Europe/Madrid.
 *
 * CAPA: Infraestructura compartida (Shared\Infrastructure).
 */
final readonly class SystemClock implements Clock
{
    public function __construct(private DateTimeZone $timezone) {}

    public function today(): DateTimeImmutable
    {
        // 'today' es un formato relativo de PHP: la fecha actual a las
        // 00:00:00, calculada EN la zona horaria indicada.
        return new DateTimeImmutable('today', $this->timezone);
    }
}
