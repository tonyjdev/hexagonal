# Explicación HEXM-0019: Caso de uso GetMenuForDate y reloj

## Explicación

La aplicación existe para responder a una pregunta: **«¿qué me toca comer hoy (o el día X)?»**. Al empezar esta tarea, las dos piezas que la resuelven ya existían, pero nadie las unía:

- el repositorio sabe qué plan está vigente en una fecha (HEXM-0018);
- el calendario sabe qué semana y qué menú corresponden a un plan en una fecha (HEXM-0017).

Faltaban dos cosas: alguien que las **orqueste**, y una forma de saber **qué día es hoy** sin que los tests dependan del día en que se ejecutan.

La idea de la solución:

- un **caso de uso de consulta**, `GetMenuForDate`, que busca el plan vigente, pide el día al calendario y devuelve una **vista de lectura** con solo primitivos;
- un **puerto `Clock`** con un único método, `today()`, que el caso de uso consulta cuando no recibe fecha. En producción lo implementa el reloj real (`SystemClock`) y en los tests, un reloj parado (`FrozenClock`);
- un **test de arquitectura** que impide que el núcleo lea el reloj del sistema por su cuenta.

Con esta tarea se cierra el nivel 4.

## Lógica de negocio

Con el plan 27, que empieza el lunes 2 de noviembre de 2026:

| Pregunta | Respuesta |
|---|---|
| Hoy es el miércoles 11/11 | Plan 27, semana 2, miércoles: la comida y la cena del día 10. |
| ¿Y el martes 03/11? | Semana 1, martes: el menú 2 de la nutricionista, en texto libre. |
| ¿Y el lunes 16/11? | Otra vez la semana 1: han pasado dos semanas completas. |
| Hoy es el sábado 14/11 | Día libre de la semana 2: no hay comida ni cena del plan. |
| El 03/01/2027 y el 04/01/2027, con el plan 28 empezando el 04/01 | El 03/01 todavía rige el 27; el 04/01 rige el 28 y empieza su semana 1. |
| El domingo 01/11, antes del plan 27 | No hay menú: *«No hay ningún plan nutricional vigente el 01/11/2026.»* |

Las reglas implicadas:

- **«Hoy» es la fecha que ve el usuario, no la del servidor** (regla 21). A las 00:30 del lunes 9 en Madrid ya toca el lunes de la semana 2, aunque en UTC todavía sea el domingo 8 de la semana 1. Por eso «hoy» se calcula en la zona horaria del usuario, Europe/Madrid.
- **Sin plan vigente no hay menú.** No es un error del usuario ni una regla incumplida: simplemente no hay nada que responder. Por eso se trata como «no encontrado», igual que una receta que no existe.
- **Preguntar no cambia nada.** Se puede preguntar por hoy, por mañana o por el mes pasado tantas veces como se quiera.
- El resto de reglas ya existían y no se tocan: la alternancia de semanas, el sábado libre y la vigencia (reglas 1, 2, 5, 19 y 20).

## Decisiones de arquitectura

### Recorrido de una consulta

```
Quien pregunta (hoy un test; en el nivel 5, la API)
   │  __invoke()            ← sin fecha
   ▼
GetMenuForDate (MealPlanning\Application)
   ├─► Clock::today()                     Shared\Application  → FrozenClock | SystemClock
   ├─► NutritionPlanRepository::inForceOn  MealPlanning\Domain → InMemory… (Eloquent en el nivel 5)
   │      └─ null → NoPlanInForce (NotFound → 404)
   ├─► MealCalendar::dayOn(plan, fecha)    MealPlanning\Domain (servicio de dominio)
   └─► ScheduledDayView::fromScheduledDay  primitivos: plan, semana, día, platos o día libre
```

### Un caso de uso que solo orquesta

`GetMenuForDate` no contiene ninguna regla del negocio. La vigencia está en el contrato del repositorio (ADR 0011) y el calendario, en `MealCalendar`. El caso de uso se limita a coordinar: elige la fecha, pide el plan, pide el día y traduce el resultado. Si mañana cambiara la regla del sábado libre, este fichero no se tocaría. Es lo que se espera de la capa de aplicación en una arquitectura hexagonal: coordinar los puertos y el dominio, sin decidir nada del negocio.

Las tres dependencias le llegan por el constructor (**inyección de dependencias**): el repositorio, el calendario y el reloj. Los tests le pasan el repositorio en memoria y un `FrozenClock`. En el nivel 5, el *service provider* le pasará Eloquent y `SystemClock`, sin cambiar ni una línea del caso de uso.

### El reloj es un puerto de aplicación compartido (ADR 0012)

- **En la capa de aplicación**, porque ningún concepto del negocio necesita saber qué día es hoy: `MealCalendar` responde lo mismo cualquier día que se le pregunte. Quien decide «pregunto por hoy» es el caso de uso. Hay además un efecto buscado: la lista blanca del dominio (ADR 0004) no incluye `Application`, así que el dominio **no puede** depender del reloj, y seguirá siendo puro y determinista.
- **En `Shared`**, porque el tiempo no pertenece a ningún contexto: el recetario lo podrá usar igual, por ejemplo para una fecha de creación.
- **Los adaptadores van en `Shared\Infrastructure`**: `SystemClock` es el único sitio del código propio que lee la fecha del sistema, y `FrozenClock` es un adaptador más, como el repositorio en memoria.

### La zona horaria se inyecta

`new SystemClock(new DateTimeZone('Europe/Madrid'))`. Laravel usa UTC por defecto, así que fiarse de la zona por defecto de PHP daría «ayer» a las 00:30. Elegir la zona es tarea de la **raíz de composición** (el *service provider* del nivel 5, leyendo la configuración), no del núcleo ni del adaptador.

### La consulta devuelve un DTO de lectura (ADR 0008)

`ScheduledDayView` (con `DishView` y `DishIngredientView`) solo contiene primitivos:

- la fecha ISO, el número de plan y la semana;
- el día de la semana, como número ISO y como nombre en español;
- `isFreeDay`;
- la comida y la cena.

Ni el agregado `NutritionPlan` ni el `ScheduledDay` salen de la aplicación. La vista junta datos de dos objetos del dominio, el número del plan y el día programado, porque una vista se diseña para quien la lee, no para copiar una clase.

### «Sin plan vigente» es un NotFound

`NoPlanInForce` vive en `MealPlanning\Application` y hereda de `Shared\Application\NotFound`, como `RecipeNotFound`. No se ha incumplido ninguna regla: es el resultado de buscar. El manejador global ya traduce cualquier `NotFound` a 404, así que la API del nivel 5 no necesitará código nuevo para este caso.

### Un guardián en el gate

Hay dos tests de arquitectura nuevos:

- **`arch()->not->toUse()`** sobre las funciones de PHP que leen el reloj (`time`, `microtime`, `date`, `strtotime`, `date_create`…). El `now()` de Laravel ya estaba prohibido.
- **Un análisis por tokens** con `PhpToken::tokenize()`. `arch()` no distingue `new DateTimeImmutable('now')` de `new DateTimeImmutable($fecha->format('Y-m-d'))`, que es legítimo y lo usa `MealCalendar`. El análisis se salta los comentarios y rechaza `new DateTime…` cuando se llama sin argumentos o con un texto literal. En el núcleo, una fecha se crea siempre a partir de otra que llega de fuera.

La única excepción es `Shared\Domain\Uuid`. Un UUID v7 lleva dentro los milisegundos en que se genera (ADR 0005), y eso es una marca técnica para ordenar identificadores, no un dato del negocio. Además, `Uuid::v7()` ya es no determinista por diseño (`random_bytes`). La excepción está escrita con su motivo en `allowedSystemClockReaders()`. El test la encontró al primer intento: es exactamente para lo que sirve un guardián.

## Alternativas descartadas

- **Un Laravel clásico: `now()` o `Carbon::today()` dentro del controlador o del servicio, y `Carbon::setTestNow()` o `$this->travelTo()` en los tests.** Funciona, pero el reloj es **global**: un test que se olvida de restaurarlo contamina a los siguientes, y el código queda atado a Carbon y a Laravel. Aquí cada caso de uso recibe su reloj por el constructor, y no hay nada global que restaurar.
- **El reloj en el dominio (`Shared\Domain` o `MealPlanning\Domain`).** Es lo más habitual en DDD, pero abriría la puerta a que una regla del dominio pidiera «hoy», cuando hoy ninguna lo necesita. Se prefiere que el dominio reciba siempre la fecha.
- **PSR-20 (`Psr\Clock\ClockInterface`).** Es un estándar, pero ofrece un instante (`now()`), no una fecha del calendario. Además, habría que añadir `psr/clock` como dependencia directa y autorizarlo en la lista blanca con un ADR. Si una librería llega a necesitarlo, se le escribe un adaptador en `Infrastructure`.
- **Cambiar `app.timezone` a Europe/Madrid.** Afectaría a toda la aplicación Laravel (logs, fechas de Eloquent, Carbon…) para resolver un problema que solo tiene «hoy».
- **Recibir la fecha como texto (`'2026-11-11'`)**, como `GetRecipe` recibe el id. `DateTimeImmutable` es un tipo nativo y permitido en el núcleo; convertir el texto de una petición es tarea del adaptador.
- **Sustituir `MealCalendar` por un doble en los tests del caso de uso.** No aporta nada: es puro y rápido, y usar el real prueba la orquestación de verdad.
- **Un test de arquitectura con expresiones regulares sobre el texto del fichero.** Saltaría con los comentarios, que en este proyecto citan mucho `new DateTimeImmutable('now')`. Los tokens de PHP separan el código de los comentarios.

## Conceptos aplicados

| Concepto | Definición breve | Dónde verlo |
|---|---|---|
| El tiempo como dependencia | «Hoy» es un dato externo que cambia sin que cambie el código; se inyecta a través de un puerto para que los tests sean deterministas. | `app-laravel/src/Shared/Application/Clock.php` |
| Puerto y adaptadores de salida | Una interfaz del núcleo y dos implementaciones intercambiables: el reloj real y uno parado. | `app-laravel/src/Shared/Infrastructure/SystemClock.php`, `FrozenClock.php` |
| *Test double* (*fake*) | Un sustituto que se comporta como el real pero lo controla el test: `FrozenClock`, y un reloj que falla si alguien lo consulta. | `app-laravel/tests/Unit/MealPlanning/Application/GetMenuForDateTest.php` |
| Caso de uso de consulta que orquesta (CQS) | Devuelve datos sin cambiar nada y coordina puertos y servicios de dominio sin reglas propias. | `app-laravel/src/MealPlanning/Application/GetMenuForDate.php` |
| DTO de lectura | Una foto con primitivos para quien la va a mostrar; las entidades no salen de la aplicación. | `app-laravel/src/MealPlanning/Application/ScheduledDayView.php`, `DishView.php` |
| «No encontrado» como resultado | La ausencia de respuesta es parte del contrato de la consulta, no una regla rota (404, no 422). | `app-laravel/src/MealPlanning/Application/NoPlanInForce.php` |
| Raíz de composición | El único sitio que elige los adaptadores concretos y su configuración (la zona horaria); llegará en el nivel 5. | Comentario de `SystemClock` y ADR 0012 |
| Fitness function con tokens de PHP | Un test de arquitectura que analiza el código fuente sin ejecutarlo, ignorando los comentarios. | `app-laravel/tests/Architecture/HexagonalLayersTest.php` (`systemClockInstantiations()`) |
| Estándar frente a interfaz propia | Un PSR facilita la interoperabilidad, pero en un núcleo con lista blanca cuesta una dependencia y puede no hablar el lenguaje del negocio. | `docs/adr/0012-el-reloj-como-puerto-de-aplicacion.md` |

## Diagramas

- [¿Qué me toca hoy?](../diagramas/HEXM-0019/que-me-toca-hoy.sequence.json) (`sequence`): ¿por dónde pasa una consulta y qué responde en cada caso? Muestra los tres caminos: sin fecha (el `Clock` da el miércoles 11/11 → semana 2), con fecha explícita (sábado 14/11 → día libre, sin consultar el reloj) y sin plan vigente (`NoPlanInForce`).
- [El reloj como puerto](../diagramas/HEXM-0019/el-reloj-como-puerto.architecture.json) (`architecture`): ¿dónde vive «hoy» y quién puede verlo? Muestra `Clock` en `Shared\Application`, sus dos adaptadores en `Shared\Infrastructure`, el dominio que recibe la fecha sin buscarla y el test de arquitectura que lo vigila.

Para verlos: `cd app-laravel && composer docs:diagrams` y abrir `docs/diagramas/HEXM-0019/que-me-toca-hoy.html` y `docs/diagramas/HEXM-0019/el-reloj-como-puerto.html` en el navegador.
