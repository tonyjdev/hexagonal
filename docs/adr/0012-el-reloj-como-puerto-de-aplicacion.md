# 0012. El reloj como puerto de aplicación

**Fecha:** 2026-11-03 · **Estado:** aceptada · **Tarea:** HEXM-0019 · **Relacionado:** [ADR 0004](0004-lista-blanca-de-dependencias-en-el-nucleo.md), [ADR 0005](0005-identificadores-uuid-v7-en-el-dominio.md), [ADR 0008](0008-consultas-dto-de-lectura-y-no-encontrado.md)

## Contexto

El caso de uso `GetMenuForDate` responde a "¿qué me toca comer hoy?", así que necesita saber qué día es hoy. Si leyera la fecha del sistema (`new DateTimeImmutable('now')`, `time()` o el `now()` de Laravel), su resultado cambiaría según el día en que se ejecutara. Sus tests dejarían de ser deterministas, y los casos que solo se dan algunos días (un sábado o el día antes del primer plan) no se podrían probar cuando se quisiera.

Por eso "hoy" llega a través de un puerto `Clock`. HEXM-0019 dejaba tres preguntas abiertas:

1. **Dónde vive el puerto:** en `MealPlanning\Domain`, en `Shared\Domain` o en `Shared\Application`.
2. **Qué interfaz usa:** la del estándar PSR-20 (`Psr\Clock\ClockInterface`, con `now(): DateTimeImmutable`) o una propia. `psr/clock` ya está en `vendor/`, pero solo porque lo instalan como dependencia Carbon y Monolog, y no está autorizado en el núcleo (ADR 0004).
3. **Qué zona horaria decide "hoy":** Laravel usa UTC por defecto, y a las 00:30 del día 11 en Madrid en UTC todavía es el día 10.

## Decisión

1. **`Shared\Application\Clock`, con un único método: `today(): DateTimeImmutable`.** Devuelve la fecha de hoy a medianoche, en la zona horaria del usuario.
   - **Va en la capa de aplicación** porque ninguna regla del negocio necesita saber qué día es hoy. `MealCalendar` recibe la fecha como argumento; quien decide preguntar por hoy es el caso de uso. Además, la lista blanca del dominio no incluye `Application`, así que el dominio **no puede** depender del reloj y seguirá siendo determinista.
   - **Va en `Shared`** porque el tiempo no pertenece a ningún contexto: `Recipes` lo podrá usar igual.
2. **Interfaz propia, no PSR-20.**
   - El negocio necesita una **fecha del calendario** con el nombre del lenguaje ubicuo (`today()`), mientras que PSR-20 da un **instante** (`now()`).
   - Una interfaz propia no obliga a autorizar ninguna librería en el núcleo.
   - Si algún día una librería pide un reloj PSR-20, se le escribe un adaptador en `Infrastructure` sin tocar este puerto.
3. **La zona horaria se inyecta en el adaptador:** `new SystemClock(new DateTimeZone('Europe/Madrid'))`. Elegirla es tarea de la raíz de composición (el *service provider* del nivel 5, que la leerá de la configuración); el núcleo no la conoce. No se cambia `app.timezone`, porque afectaría a toda la aplicación Laravel (logs, Carbon…).
4. **Dos adaptadores** en `Shared\Infrastructure`:
   - `SystemClock`, el reloj real y el único sitio del código propio que lee la fecha del sistema;
   - `FrozenClock`, una fecha fija para los tests: `FrozenClock::on('2026-11-11')`.
5. **Dos tests de arquitectura** prohíben leer el reloj del sistema en el núcleo (`Domain` y `Application` de cada contexto):
   - `arch()` con `not->toUse()` para las funciones de fecha y hora de PHP (`time`, `microtime`, `date`, `strtotime`, `date_create`…). El `now()` y el `today()` de Laravel ya los prohibía la lista negra;
   - un análisis por *tokens* (`PhpToken::tokenize`) que rechaza `new DateTime(Immutable)` sin argumentos o con un texto literal (`'now'`, `'+1 day'`…). Hace falta porque `arch()` no distingue `new DateTimeImmutable('now')` de `new DateTimeImmutable($otraFecha->format('Y-m-d'))`, que es legítimo y lo usa `MealCalendar`. En el núcleo una fecha se crea siempre **a partir de otra** que llega de fuera.
6. **Única excepción: `Shared\Domain\Uuid`**, que usa `microtime()` porque un UUID v7 lleva dentro los milisegundos en que se genera (ADR 0005). Ese instante no es un dato del negocio, sino una marca técnica para ordenar identificadores. `Uuid::v7()` ya es no determinista por diseño (usa `random_bytes`), y ningún test depende de su valor. Tampoco se le podría inyectar el `Clock`: el dominio no lo ve, y el puerto da una fecha, no milisegundos. La excepción figura con su motivo en `allowedSystemClockReaders()`.

## Consecuencias

- **A favor:**
  - Los tests del caso de uso son deterministas: cada uno elige qué día es hoy, sin reloj global (lo que en Laravel serían `Carbon::setTestNow()` o `travelTo()`).
  - El dominio sigue siendo puro: recibe las fechas y no las busca.
  - El gate rompe si alguien escribe `time()` o `new DateTimeImmutable('now')` en el núcleo.
  - Elegir la zona horaria es una decisión de configuración, no del código.
- **En contra:**
  - Es una interfaz más que mantener, en lugar de un estándar.
  - El análisis es estático: `new DateTimeImmutable($texto)` con `$texto = 'now'` en una variable no se detecta. Lo cubre la revisión de código, como el resto de límites del ADR 0004.
  - `today()` solo da fechas. Si algún caso de uso necesita la hora (por ejemplo, "a partir de las 18:00, muestra ya la cena"), habrá que ampliar el puerto con un `now()`.
- **Revisar cuando:**
  - un caso de uso necesite instantes y no solo fechas;
  - el usuario pueda elegir su propia zona horaria: entonces quizá llegue como argumento de cada consulta y no del adaptador.
