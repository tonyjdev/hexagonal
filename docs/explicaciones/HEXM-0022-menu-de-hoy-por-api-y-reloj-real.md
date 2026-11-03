# Explicación HEXM-0022: Menú de hoy por API y reloj real

## Explicación

La pregunta clave de la aplicación, **«¿qué me toca comer hoy (o el día X)?»**, ya tenía respuesta en el núcleo desde HEXM-0019 (`GetMenuForDate`), pero solo dentro de los tests. Faltaban dos cosas para usarla de verdad:

1. **una puerta de entrada** desde fuera: un endpoint HTTP;
2. **un reloj real.** Hasta ahora el puerto `Clock` solo tenía un reloj parado (`FrozenClock`) en los tests; en la aplicación no había nadie que dijera qué día es hoy.

La idea de la solución:

- un **adaptador *driving*** (controlador, *Form Request* y *API Resource*) que publica el caso de uso que ya existía, **sin tocar ni una línea del núcleo**;
- una **raíz de composición compartida**, `SharedServiceProvider`, que enchufa el puerto `Clock` al reloj del sistema (`SystemClock`) en la zona horaria del usuario, leída de la configuración (`config/clock.php`);
- en los tests, «hoy» se fija **sustituyendo el puerto en el contenedor**, sin relojes globales.

## Lógica de negocio

Con el plan 27, que empieza el lunes 2 de noviembre de 2026:

| Pregunta | Respuesta |
|---|---|
| ¿Qué me toca hoy? (martes 3 de noviembre en Madrid) | Semana 1, martes: el menú 2 de la nutricionista, «Pollo al horno con patata asada» y de cena «Crema de calabacín y tortilla francesa». |
| ¿Y el miércoles 11? | Semana 2, miércoles: lo que el usuario escribió para el día 10. |
| ¿Y el sábado 14? | Día libre de la semana 2: no hay comida ni cena del plan. |
| ¿Y el domingo 1 de noviembre? | No hay menú: *«No hay ningún plan nutricional vigente el 01/11/2026.»* |

Las reglas implicadas ya existían; esta tarea las hace visibles por HTTP:

- **«Hoy» es la fecha del usuario, no la del servidor** (regla 21). A las 00:30 del lunes 9 en Madrid ya toca el lunes de la semana 2, aunque el servidor, en UTC, todavía esté en el domingo 8. La zona del usuario se configura; hoy es Europe/Madrid.
- **La alternancia de semanas, el sábado libre y la vigencia** (reglas 1, 2, 5, 19 y 20) las sigue aplicando el núcleo, igual que antes.
- **Preguntar por un día sin plan no es un error del usuario**: es una respuesta, «no hay nada» (un 404).
- **Una fecha mal escrita** («abc», «11/11/2026», o un «2026-13-40» que no existe) sí es un error de la petición: se rechaza antes de preguntar al núcleo.

## Decisiones de arquitectura

### Recorrido de una consulta

```
GET /api/menu[?date=AAAA-MM-DD]
   │  MealPlanning\Infrastructure\Http
   ▼
GetMenuForDateRequest ── ¿date mal formada? ──► 422 {"message", "errors": {"date": …}}
   │ requestedDate(): DateTimeImmutable o null (= hoy)
   ▼
GetMenuForDateController ──► GetMenuForDate (MealPlanning\Application, sin cambios)
                                ├─ null → Clock::today() ──► SystemClock(Europe/Madrid)   [SharedServiceProvider]
                                ├─ inForceOn(fecha)      ──► EloquentNutritionPlanRepository [MealPlanningServiceProvider]
                                │      └─ null → NoPlanInForce ──► 404 (manejador global de NotFound)
                                └─ MealCalendar::dayOn()  ──► ScheduledDayView
   ▼
ScheduledDayResource ──► 200 {"data": {date, plan_number, week_number, weekday, is_free_day, lunch, dinner}}
```

### Un adaptador nuevo, el núcleo intacto

`GetMenuForDate` se escribió y se probó sin HTTP en HEXM-0019. Ahora lo publica un controlador, y `git diff` sobre `MealPlanning\Domain`, `MealPlanning\Application`, `Shared\Domain` y `Shared\Application` sale **vacío**. Es la promesa de la arquitectura hexagonal en su forma más pura: la entrada HTTP es un detalle que se enchufa desde fuera.

El reparto de responsabilidades sigue el del recetario (ADR 0007 y 0008):

- la ***Form Request*** solo comprueba la forma de `?date` (`date_format:Y-m-d`, que además rechaza fechas que no existen) y la convierte en `DateTimeImmutable`, sin calcular «hoy»;
- el **controlador** traduce la petición en la llamada al caso de uso, sin reglas;
- el ***API Resource*** decide el JSON: claves en `snake_case`, como en el recetario (`unit_label`, `to_taste`), y **las mismas claves con menú y en el día libre** (`lunch` y `dinner` a `null`), para que el cliente lea siempre la misma estructura;
- el **404** lo produce el manejador global de `NotFound`, que ya existía. Un «no encontrado» nuevo de otro contexto no necesitó ni una línea en `bootstrap/app.php`, tal como anunciaba el ADR 0008.

### El reloj, en una raíz de composición compartida (actualización del ADR 0012)

El *binding* `Clock` → `SystemClock` vive en **`SharedServiceProvider`** y no en `MealPlanningServiceProvider`, por la misma razón por la que el puerto está en `Shared\Application`: el tiempo no es de ningún contexto. Si lo registrara `MealPlanning`, el recetario dependería de él para saber qué día es.

Queda así una raíz de composición por contexto, más la compartida, todas registradas en `bootstrap/providers.php`.

- **`singleton` y no `bind`**: `SystemClock` no guarda la fecha y calcula «hoy» en cada llamada, así que compartir la instancia no la deja anticuada aunque el proceso dure días.
- **La zona horaria es configuración, no código.** `config/clock.php` lee `APP_USER_TIMEZONE`, por defecto Europe/Madrid. Cambiarla cambia «hoy» sin tocar ni el núcleo ni el adaptador.
- **No se cambia `app.timezone`**, que sigue en UTC: afectaría a toda la aplicación Laravel (logs, Carbon, Eloquent) para resolver algo que solo afecta a «hoy».
- **Una zona mal escrita** («Europe/Madird») hace fallar la resolución del reloj, en lugar de calcular «hoy» en una zona equivocada.

### «Hoy» en los tests: sustituir el puerto, no el tiempo

Cada test de *feature* pone en el contenedor el reloj que necesita: `$this->app->instance(Clock::class, FrozenClock::on('2026-11-11'))`. El caso de uso lo recibe por su constructor exactamente como en producción recibe `SystemClock`.

Para la fecha explícita, el test pone **un reloj que falla si alguien lo consulta**: así demuestra que, con `?date`, el reloj ni se toca. Además, un test del *provider* comprueba que, en la aplicación real, el puerto es `SystemClock` en la zona configurada.

## Alternativas descartadas

- **Un Laravel clásico**: `now()` o `today()` de Laravel dentro del controlador (o de un modelo) y `$this->travelTo()` o `Carbon::setTestNow()` en los tests. Funciona, pero el reloj es **global**: un test que se olvida de restaurarlo contamina a los siguientes. Además, el núcleo quedaría atado a Carbon, y la regla de «hoy en la zona del usuario» quedaría repartida por el código. Aquí hay un único puerto, un único adaptador real y una única línea de configuración.
- **Registrar el reloj en `MealPlanningServiceProvider`**: acoplaría el recetario a `MealPlanning` el día que necesite saber qué día es.
- **Cambiar `app.timezone` a Europe/Madrid**: lo descartó el ADR 0012 y se mantiene.
- **Calcular «hoy» en la *Form Request* o en el controlador** (por ejemplo, `$request->date('date') ?? now('Europe/Madrid')`): el adaptador HTTP decidiría qué es «hoy», y el siguiente adaptador (un comando de Artisan, el agente del nivel 13) tendría que repetirlo. Con `null`, el caso de uso pregunta al puerto y la decisión está en un solo sitio.
- **Quitar las claves `lunch` y `dinner` en el día libre**: obligaría al cliente a comprobar si existen. Con `null` y `is_free_day` la estructura es siempre la misma.
- **Un método `date()` en la *Form Request***: choca con `Illuminate\Http\Request::date()`, que ya existe con otra firma. Se llama `requestedDate()`.

## Conceptos aplicados

| Concepto | Definición breve | Dónde verlo |
|---|---|---|
| Adaptador *driving* sobre un caso de uso existente | Una entrada nueva que no toca el núcleo: petición → caso de uso → vista → JSON. | `app-laravel/src/MealPlanning/Infrastructure/Http/GetMenuForDateController.php` |
| Validación de forma en una consulta | La frontera comprueba `?date`; si hay plan o no lo responde el caso de uso. | `app-laravel/src/MealPlanning/Infrastructure/Http/GetMenuForDateRequest.php` |
| *API Resource* de un DTO con dos formas | Las mismas claves para un día con menú y para el día libre. | `app-laravel/src/MealPlanning/Infrastructure/Http/ScheduledDayResource.php` |
| Raíz de composición compartida | El *provider* que enchufa los puertos de `Shared`, independiente de los contextos. | `app-laravel/src/Shared/Infrastructure/SharedServiceProvider.php` |
| La zona horaria como decisión de despliegue | La configuración elige el adaptador concreto (su zona) sin tocar el código. | `app-laravel/config/clock.php` |
| *Binding* con una closure y `singleton` | Decirle al contenedor cómo construir un objeto que necesita parámetros (la zona). | `SharedServiceProvider::register()` |
| Sustituir un puerto en el contenedor durante un test | `instance(Clock::class, FrozenClock)` en lugar de un reloj global. | `app-laravel/tests/Feature/MealPlanning/Infrastructure/Http/GetMenuForDateEndpointTest.php` |
| Traducción única de «no encontrado» | Un 404 para cualquier `NotFound` de cualquier contexto, en un solo manejador. | `app-laravel/bootstrap/app.php` |

## Diagramas

- [¿Qué me toca hoy? por HTTP](../diagramas/HEXM-0022/menu-de-hoy-por-http.sequence.json) (`sequence`): ¿qué recorre una petición a `/api/menu`? Muestra los tres caminos: hoy según el reloj real, un día concreto sin consultar el reloj, y el 404 sin plan vigente.
- [Tres raíces de composición](../diagramas/HEXM-0022/raices-de-composicion.architecture.json) (`architecture`): ¿quién decide qué adaptador hay detrás de cada puerto? Muestra los tres *service providers*, la configuración de la zona horaria y cómo los tests sustituyen el reloj en el contenedor.

Para verlos: `cd app-laravel && composer docs:diagrams` y abrir `docs/diagramas/HEXM-0022/menu-de-hoy-por-http.html` y `docs/diagramas/HEXM-0022/raices-de-composicion.html` en el navegador.
