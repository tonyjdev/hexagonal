<?php

declare(strict_types=1);

namespace Shared\Application;

use DateTimeImmutable;

/**
 * Clock: el reloj de la aplicación. Responde "¿qué día es hoy?".
 *
 * CONCEPTO: el tiempo como dependencia (puerto Clock)
 * "Hoy" es un dato que viene de FUERA del programa, como lo que hay en una
 * base de datos: cambia cada día sin que cambie el código. Si un caso de uso
 * escribiera `new DateTimeImmutable('now')` o el now() de Laravel, su
 * resultado dependería del día en que se ejecuta, y sus tests serían frágiles:
 * un test "el miércoles toca la semana 2" pasaría un día y fallaría al
 * siguiente. Por eso "hoy" se pide a través de un puerto, que se inyecta como
 * cualquier otra dependencia:
 *   - en producción, SystemClock (la fecha real en la zona del usuario);
 *   - en los tests, FrozenClock (una fecha fija que elige el test).
 * Un test de arquitectura impide leer el reloj del sistema en el núcleo
 * (HexagonalLayersTest, HEXM-0019).
 *
 * DECISIÓN: capa de aplicación compartida, no dominio (HEXM-0019, ADR 0012)
 * Ninguna regla del negocio necesita saber qué día es hoy: MealCalendar
 * recibe la fecha como argumento y responde lo mismo cualquier día que se le
 * pregunte. Quien decide "pregunto por hoy" es el CASO DE USO. Poniendo el
 * puerto en Shared\Application, el dominio ni siquiera puede depender de él
 * (su lista blanca no incluye Application): seguirá siendo puro y
 * determinista. Y es Shared porque el tiempo no es de ningún contexto: el
 * recetario lo podrá usar igual (por ejemplo, para una fecha de creación).
 *
 * DECISIÓN: interfaz propia y no PSR-20 (HEXM-0019, ADR 0012)
 * PSR-20 (Psr\Clock\ClockInterface) es el estándar de PHP para relojes, con
 * un único método now(): un INSTANTE (fecha, hora y zona). Aquí se necesita
 * otra cosa, una FECHA DEL CALENDARIO ("el 11 de noviembre"), y con el nombre
 * del lenguaje ubicuo: today(). Además, usar PSR-20 exigiría autorizar
 * psr/clock en la lista blanca del núcleo con un ADR (ADR 0004). Si algún
 * día una librería pide un reloj PSR-20, se puede escribir un adaptador en
 * Infrastructure sin tocar este puerto.
 *
 * CAPA: Aplicación compartida (Shared\Application). Es un puerto de salida:
 * lo implementan los adaptadores de Shared\Infrastructure.
 */
interface Clock
{
    /**
     * La fecha de hoy, a medianoche, en la zona horaria del usuario.
     *
     * Se devuelve a medianoche para que nadie dependa de la hora: el núcleo
     * trabaja con fechas del calendario (ver MealCalendar). La zona horaria
     * la elige el adaptador, no quien pregunta.
     */
    public function today(): DateTimeImmutable;
}
