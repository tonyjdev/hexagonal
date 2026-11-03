# Nivel 04: Calendario

**Fecha:** 2026-11-03 · **Etiqueta git:** `nivel-04`

Tareas: HEXM-0017 (servicio de dominio `MealCalendar`), HEXM-0018 (repositorio de planes y plan vigente) y HEXM-0019 (caso de uso `GetMenuForDate` y reloj).

## Qué se ha construido

- **La respuesta a la pregunta clave de la aplicación: "¿qué me toca comer hoy (o el día X)?"**, todavía sin HTTP ni base de datos.
- **El calendario** (HEXM-0017): el servicio de dominio `MealCalendar::dayOn(plan, fecha)` devuelve un `ScheduledDay`, es decir, qué semana toca (1 o 2) y su menú, o que es el día libre. Aplica tres reglas:
  - las semanas se alternan desde el lunes de inicio;
  - el sábado es libre;
  - antes del inicio no hay menú.

  Cuenta días del calendario: ni la hora, ni la zona horaria, ni el horario de verano cambian el resultado.
- **El repositorio de planes** (HEXM-0018): el puerto `NutritionPlanRepository` (`save`, `find`, `inForceOn`), con su adaptador en memoria, sus tests de contrato y el `NutritionPlanMother`. La vigencia y "dos planes no empiezan el mismo día" (`DuplicatedPlanStart`) forman parte del contrato del puerto.
- **El caso de uso y el reloj** (HEXM-0019):
  - `GetMenuForDate`, el primer caso de uso de `MealPlanning`;
  - el puerto `Shared\Application\Clock` (`today()`), con `SystemClock` y `FrozenClock`;
  - el DTO de lectura `ScheduledDayView` (con `DishView` y `DishIngredientView`);
  - `NoPlanInForce`, un `NotFound`.
- **Dos tests de arquitectura nuevos:** el núcleo no lee el reloj del sistema, ni con funciones (`time()`, `date()`…) ni con `new DateTimeImmutable('now')`.
- **381 tests** en el gate (eran 334 al cerrar el nivel 3).

```
GetMenuForDate(?fecha)                         (MealPlanning\Application)
├── fecha ?? Clock::today()                    (Shared\Application → SystemClock | FrozenClock)
├── NutritionPlanRepository::inForceOn(fecha)  (puerto del dominio → en memoria; Eloquent en el nivel 5)
│     └── null → NoPlanInForce (NotFound → 404)
├── MealCalendar::dayOn(plan, fecha)           (servicio de dominio → ScheduledDay)
└── ScheduledDayView                           (primitivos: plan, semana, día, comida y cena, o día libre)
```

## Conceptos aprendidos

- **Servicio de dominio:** lógica del negocio sin estado que no encaja en ningún agregado. Recibe lo que necesita (el plan y la fecha) y no busca nada.
- **Fechas del calendario frente a instantes:** "el 9 de noviembre" no es "el 9 de noviembre a las 00:30 en Madrid". Contar segundos entre fechas falla con el horario de verano; hay que contar días del calendario.
- **Reglas entre agregados en el contrato de un puerto:** lo que compara varios planes lo responde quien los ve todos, el repositorio, y se fija con tests de contrato que cumplirá cada adaptador.
- **El tiempo como dependencia:** el puerto `Clock`. Un `now()` dentro del código hace que los tests dependan del día en que se ejecutan; con `FrozenClock` cada test decide qué día es hoy, sin relojes globales como `Carbon::setTestNow()`.
- **Caso de uso de consulta que orquesta:** `GetMenuForDate` no tiene ninguna regla propia. Coordina un puerto, un servicio de dominio y el reloj, y traduce el resultado a una vista.
- **PSR-20 frente a una interfaz propia:** un estándar no siempre habla el lenguaje del negocio (`now()` frente a `today()`), y en un núcleo con lista blanca cada librería tiene un coste.
- **Tests de arquitectura con *tokens* de PHP:** cuando `arch()` no basta (misma clase, uso legítimo o no), `PhpToken::tokenize()` permite analizar el código sin los comentarios.

## Decisiones tomadas (y por qué)

- **El calendario es un servicio de dominio y no un método de `NutritionPlan`** (HEXM-0017): el agregado se ocupa de su consistencia; interpretar el plan en el tiempo es otra responsabilidad que puede crecer.
- **La vigencia y la unicidad del inicio son parte del contrato del repositorio**, y `save()` rechaza dos planes que empiecen el mismo día ([ADR 0011](../adr/0011-reglas-entre-agregados-en-el-contrato-del-repositorio.md)).
- **El reloj se decide en el [ADR 0012](../adr/0012-el-reloj-como-puerto-de-aplicacion.md):**
  - es un puerto de **aplicación** compartido (`Shared\Application\Clock`), para que el dominio no pueda preguntar por hoy;
  - usa una interfaz propia (`today()`) y no PSR-20;
  - la zona horaria se inyecta en `SystemClock` desde la raíz de composición, en lugar de cambiar `app.timezone`.
- **`Uuid::v7()` es la única excepción a "el núcleo no lee el reloj":** su marca de tiempo es técnica, no del negocio, y la excepción queda escrita con su motivo.
- **`GetMenuForDate` recibe un `DateTimeImmutable` opcional**, no un texto: es un tipo nativo de PHP y la conversión es cosa del adaptador.

## Dificultades / dudas

- **`arch()->toUse()` no distingue argumentos:** `new DateTimeImmutable('now')` y `new DateTimeImmutable($fecha)` usan la misma clase, y por eso hizo falta el análisis por *tokens*.
- **El primer test del reloj falló con razón:** encontró el `microtime()` de `Uuid`. Hubo que decidir si era una violación o una excepción legítima.
- **El test de `SystemClock` prueba el reloj real:** en teoría puede fallar si se ejecuta justo al dar la medianoche. Es el precio de probar el adaptador real y la razón de que el resto de tests usen `FrozenClock`.
- **Pendiente:**
  - el endpoint `GET /api/menu?date=…`, el *binding* de `Clock` → `SystemClock(Europe/Madrid)` en un *service provider* y la persistencia de los planes con Eloquent (nivel 5);
  - una semana que cruza el cambio de plan, o un plan que termina sin que empiece otro (ADR 0010 y 0011).

## Para profundizar

- PSR-20 (*Clock*) en php-fig.org, y por qué se añadió al estándar.
- Mark Seemann, *Dependency Injection Principles, Practices, and Patterns*: el reloj como ejemplo clásico de dependencia volátil.
- Eric Evans, *Domain-Driven Design*, capítulo 5 (servicios de dominio).
- La documentación de PHP sobre `PhpToken::tokenize()` y los formatos relativos de fecha (`'today'`, `'midnight'`).
