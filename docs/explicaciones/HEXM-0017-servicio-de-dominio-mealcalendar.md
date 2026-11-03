# Explicación HEXM-0017: Servicio de dominio MealCalendar

## Explicación

La pregunta clave de la aplicación es **«¿qué me toca comer hoy (o el día X)?»**. Para responderla hacen falta tres cosas:

1. saber qué plan está vigente ese día (HEXM-0018);
2. saber qué día es «hoy» (HEXM-0019);
3. dado un plan y una fecha, saber qué toca: qué semana, qué día y qué menú.

Esta tarea resuelve la tercera, que es la que tiene **las reglas del negocio**: las semanas se alternan, el sábado es libre y antes del inicio no hay menú. Se resuelve solo con el dominio, sin reloj ni repositorio, para poder probarla con cualquier fecha.

La idea de la solución:

- un **servicio de dominio sin estado**, `MealCalendar`, con un único método `dayOn(plan, fecha)`;
- que devuelve un **value object**, `ScheduledDay`, con la semana, el día de la semana y su menú, o el día libre;
- y que cuenta **días del calendario**, no horas, para que ni la hora, ni la zona horaria, ni el cambio al horario de verano muevan el resultado.

## Lógica de negocio

Con el plan 27, que empieza el lunes 2 de noviembre de 2026:

| Fecha | Qué toca | Por qué |
|---|---|---|
| Dom 01/11 | Nada: «el plan 27 empieza el 02/11/2026» | Antes del inicio, el plan no tiene menú. |
| Lun 02/11 | Semana 1, menú 1 | Día 0 del plan. |
| Sáb 07/11 | Libre | El sábado no tiene menú en ninguna semana. |
| Dom 08/11 | Semana 1, menú 6 | El menú 6 cae en domingo (HEXM-0013). |
| Lun 09/11 | Semana 2, día 8 | Ha pasado una semana completa: toca la otra. |
| Sáb 14/11 | Libre | El día 13 de la semana 2, también libre. |
| Lun 16/11 | Semana 1, menú 1 | Dos semanas completas: vuelve la primera. |
| Mié 17/02/2027 | Semana 2, día 10 | Quince semanas completas (impar): semana 2. |

- **Las semanas se alternan desde el lunes de inicio**, empezando por la 1 (regla 1). Lo que decide es cuántas semanas completas han pasado: si es un número par toca la 1, y si es impar, la 2.
- **El sábado es libre en las dos semanas**, pero sigue perteneciendo a una semana. «El sábado de la semana 2» importa para aprovechar los sobrantes de esa semana.
- **Antes del inicio, el plan no tiene menú** (regla 20). Esa fecha será de otro plan o de ninguno, y eso lo decidirá el plan vigente (HEXM-0018).
- **Cuenta la fecha tal como la ve quien pregunta.** A las 00:30 del lunes 9 en Madrid ya es lunes, aunque en UTC todavía sea domingo. Y la semana del cambio de hora de marzo sigue siendo una semana de siete días, aunque tenga una hora menos.

## Decisiones de arquitectura

**Todo es dominio puro.** `MealCalendar`, `ScheduledDay` e `InvalidScheduledDay` viven en `MealPlanning\Domain`, sin Laravel, base de datos ni HTTP, y lo garantizan los tests de arquitectura. Solo usan PHP nativo: `DateTimeImmutable`, `DateTimeZone`, `intdiv()` y `LogicException`. No hay puertos ni adaptadores nuevos.

**Por qué un servicio de dominio y no un método del agregado.** `NutritionPlan` tiene todos los datos que necesita el cálculo, así que `NutritionPlan::dayOn()` habría funcionado. Se eligió un servicio porque:

- el agregado se ocupa de su **consistencia** (las dos semanas, el lunes de inicio, las pautas sin repetir), y el calendario es otra responsabilidad: **interpretar el plan en el tiempo**;
- el calendario puede crecer más allá de un plan (por ejemplo, una semana que cruza el cambio de un plan al siguiente) sin engordar el agregado;
- es el nombre que el negocio ya usa en el glosario.

Es una clase con un método de instancia, y no una función estática, para poder **inyectarla** en el caso de uso de HEXM-0019 como cualquier otra dependencia.

**Qué sabe y qué no sabe el servicio.** Recibe el plan y la fecha como argumentos: no busca el plan vigente ni pregunta qué día es hoy. Así queda el recorrido completo cuando se cierre el nivel:

```
GetMenuForDate (Application, HEXM-0019)
   │  1. fecha = la que llega, o la de hoy según Clock
   │  2. plan = NutritionPlanRepository::inForceOn(fecha)   (HEXM-0018)
   │  3. día = MealCalendar::dayOn(plan, fecha)            ◄── esta tarea
   ▼
ScheduledDay ─► DTO de lectura para la API o la web (nivel 5)
```

Las dependencias van hacia dentro: el caso de uso conoce el servicio, y el servicio solo conoce el dominio.

**Fechas del calendario frente a instantes.** El cálculo:

1. toma el año-mes-día de cada fecha en **su propia** zona horaria;
2. reconstruye las dos fechas en UTC, que no tiene cambios de hora;
3. cuenta los días con `diff()`.

Así:

- la hora no influye: las 23:59 del domingo siguen siendo domingo;
- se respeta la fecha de quien pregunta, aunque el plan esté guardado en otra zona horaria;
- el cambio de hora no descuadra nada: una prueba de mutación confirmó que, con `segundos / 86400`, fallan justo los dos tests de zona horaria y de horario de verano.

**`ScheduledDay` con dos constructores con nombre.** `withMenu()` toma el día de la semana del propio `MenuDay`, y `freeDay()` solo admite un sábado. Así no se puede construir un «sábado con menú» ni un «martes sin menú». Si a una semana le faltara el menú de un día laborable, sería un error de programación (`LogicException`): `MenuWeek` ya lo garantiza, y no es un error del usuario (`DomainException`).

## Alternativas descartadas

- **Cómo se haría en un Laravel clásico.** Probablemente un método en el modelo Eloquent (`$plan->menuFor(now())`) o en un `PlanService` de `app/Services`. Usaría Carbon y `now()` directamente, y se comprobaría con `Carbon::setTestNow()`. Funciona, pero mezcla tres cosas: buscar el plan, saber qué día es hoy y aplicar las reglas del calendario. Además ata el cálculo a Laravel. Aquí las reglas viven solas en el dominio, se prueban con cualquier fecha sin trucos, y el «hoy» llegará por un puerto (`Clock`) en HEXM-0019.
- **Método `NutritionPlan::dayOn()`.** Era válido; se descartó por los motivos de arriba (responsabilidad y crecimiento).
- **Devolver un array o varios valores sueltos** (`[2, Weekday::Wednesday, $menu]`). Pierde el nombre del concepto y las reglas («un día libre no tiene menú»), y PHPStan no conoce su forma.
- **Devolver `?MenuDay` (null = día libre).** Se perdería la semana que toca, y el `null` mezclaría «libre» con «no hay nada». Además obligaría a quien llama a volver a calcular la semana.
- **Contar segundos y dividir entre 86 400.** Falla con el cambio de hora: la semana de marzo tiene 6,96 días y se trunca a 6. Hay un test que lo demuestra.
- **Usar `diff()` directamente sobre las fechas originales.** Funciona si las dos están en la misma zona horaria, pero no con un plan en UTC y una consulta en Madrid. Las fechas del calendario en UTC resuelven los dos casos.
- **Devolver «sin menú» en vez de lanzar un error** cuando la fecha es anterior al inicio. Esconde un error de quien llama: el caso de uso debe preguntar al plan vigente, y si aun así llega una fecha anterior, algo va mal. HEXM-0018 hará que no ocurra.

## Conceptos aplicados

| Concepto | Definición breve | Dónde verlo |
|---|---|---|
| Servicio de dominio | Lógica de negocio sin estado que no pertenece de forma natural a una entidad ni a un value object; recibe lo que necesita y devuelve un valor. | `MealCalendar` (bloques `CONCEPTO:` y `DECISIÓN:`) |
| Value object como resultado de una consulta | El resultado tiene nombre, reglas y forma conocida en lugar de ser un array suelto. | `ScheduledDay` |
| Constructores con nombre para casos excluyentes | Dos formas de crear el valor (con menú o libre) que impiden combinaciones imposibles. | `ScheduledDay::withMenu()` y `freeDay()` |
| Fecha del calendario frente a instante | Comparar año-mes-día en su zona y llevarlo a UTC, no horas ni segundos. | `MealCalendar::calendarDaysBetween()` |
| Aritmética de fechas con PHP nativo | `DateTimeImmutable::diff()`, `->days`, `->invert` e `intdiv()`, sin Carbon. | `MealCalendar.php` |
| Test de mutación | Estropear a propósito el código para ver qué test lo detecta. | Tests «uses the calendar date…» e «is not fooled by the daylight saving time change» de `MealCalendarTest.php` |
| `LogicException` frente a `DomainException` | Error de programación (imposible si se cumplen las invariantes) frente a error de negocio para el usuario. | `MealCalendar::dayOn()` |

## Diagramas

- [¿Qué toca el día X?](../diagramas/HEXM-0017/que-toca-el-dia-x.workflow.json) (`workflow`): ¿qué pasos sigue `MealCalendar::dayOn()` y en cuál se decide el error, el día libre o el menú?
- [Alternancia de semanas](../diagramas/HEXM-0017/alternancia-de-semanas.timeline.json) (`timeline`): ¿qué toca en cada una de las fechas de ejemplo del plan 27, de noviembre de 2026 al cambio de hora de marzo de 2027?

Para verlos: `cd app-laravel && composer docs:diagrams` y abrir `docs/diagramas/HEXM-0017/<slug>.html` en el navegador.
