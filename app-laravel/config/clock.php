<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Reloj de la aplicación (puerto Shared\Application\Clock)
|--------------------------------------------------------------------------
|
| CONCEPTO: la zona horaria es una decisión de despliegue (HEXM-0022, ADR 0012)
| "Hoy" depende de dónde está el usuario: a las 00:30 del día 11 en Madrid,
| en UTC todavía es el día 10. Esa zona no la decide el núcleo (que solo
| pide today() al puerto) ni el adaptador (SystemClock la recibe en su
| constructor): la decide la raíz de composición, SharedServiceProvider,
| leyendo este fichero.
|
| Es una clave propia y no app.timezone a propósito: app.timezone (UTC)
| afecta a toda la aplicación Laravel (logs, Carbon, Eloquent); esta solo
| dice en qué zona vive el usuario para saber qué día es hoy.
|
| Se puede cambiar por entorno con APP_USER_TIMEZONE (un identificador de
| zona de PHP: "Europe/Madrid", "Atlantic/Canary", "America/Mexico_City").
*/

return [
    'timezone' => env('APP_USER_TIMEZONE', 'Europe/Madrid'),
];
