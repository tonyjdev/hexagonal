# Explicación HEXM-0023: Histórico de planes

## Explicación

Cada dos meses la nutricionista entrega un plan nuevo, y con él el anterior deja de estar vigente. Pero no desaparece: el usuario quiere poder preguntar «¿qué cenaba en el plan 25?». Hasta ahora la aplicación solo sabía qué plan rige en una fecha (`inForceOn`). Faltaban tres cosas:

1. **listar todos los planes**, del más reciente al más antiguo;
2. **saber desde cuándo y hasta cuándo rigió cada uno**, una información que no se guarda en ningún sitio;
3. **consultar un plan entero** del histórico.

Al preparar el histórico apareció además una pregunta del negocio que el dominio no respondía: ¿pueden dos planes tener el mismo número? (`domain_issue` de HEXM-0021). La respuesta del usuario fue que no. Esta tarea añade esa regla y cierra el nivel 5.

La idea de la solución:

- **la vigencia es una regla del dominio**: cada plan rige hasta el día antes del siguiente. Se escribe una vez, en `NutritionPlanHistory` y `ValidityPeriod`, a partir de los planes que devuelve el repositorio;
- **el estado respecto a hoy** (vigente, finalizado o próximo) **se calcula al consultar**, con el `Clock`, porque cambia cada día;
- **el puerto gana `history()`**, que devuelve los agregados completos, y **una regla más en su contrato**: el número de plan es único.

## Lógica de negocio

Con los planes 26 (inicio el lunes 7 de septiembre de 2026) y 27 (inicio el lunes 2 de noviembre de 2026), y preguntando el 11 de noviembre:

| Plan | Desde | Hasta | Estado |
|---|---|---|---|
| 27 | 02/11/2026 | — (hasta que llegue otro) | vigente |
| 26 | 07/09/2026 | 01/11/2026 | finalizado |

- **Un plan rige desde su lunes de inicio hasta el día antes de que empiece el siguiente** (regla 19). El 26 termina el 1 de noviembre porque el 2 empieza el 27. El fin no se apunta en ningún sitio: si mañana se registra un plan 28 para el 4 de enero, el 27 pasará a terminar el 3 de enero sin tocarlo.
- **El estado depende del día en que se pregunte.** El 1 de noviembre, el 26 está vigente y el 27 es **próximo**: ya está registrado, pero aún no ha empezado. El 2 de noviembre, el 26 pasa a **finalizado**.
- **El orden lo da la fecha de inicio**, no el orden en que se registraron: dar de alta el 25 después del 27, como histórico, no lo desordena.
- **Dos planes no pueden tener el mismo número** (regla 22). Si ya existe el plan 27 y se intenta registrar otro «plan 27» que empieza el 4 de enero, se rechaza: *«Ya existe un plan 27 (empieza el 02/11/2026): no puede haber dos planes con el mismo número.»* Corregir el propio plan 27 sí está permitido. Si además coincide el día de inicio, se avisa primero de eso.
- **Un plan del histórico se consulta entero**: sus pautas fijas, sus intercambios con las reglas y las dos semanas. Si el plan no existe, la respuesta es «no encontrado».

## Decisiones de arquitectura

### Dónde está cada pieza

```
MealPlanning\Domain        NutritionPlanHistory  — ordena los planes y deriva el periodo de cada uno (regla 19)
                           ValidityPeriod        — inicio + último día (o sin fin); includes / hasEndedBefore / startsAfter
                           NutritionPlanRepository::history()  + regla 22 en save()  → DuplicatedPlanNumber
MealPlanning\Application   ListNutritionPlans    — history() + NutritionPlanHistory + Clock → NutritionPlanSummaryView (PlanStatus)
                           GetNutritionPlan      — find() → NutritionPlanView, o PlanNotFound (404)
MealPlanning\Infrastructure
  ├── InMemory… / Eloquent… — history() con ORDER BY starts_on DESC; índice único en number (migración nueva)
  └── Http                  — GET /api/nutrition-plans[/{id}], NutritionPlanSummaryResource, NutritionPlanResource
```

### La regla en el dominio, la presentación en la aplicación

«Cada plan rige hasta el día antes del siguiente» compara **varios** planes, así que ningún `NutritionPlan` puede responderlo solo. El repositorio responde la pregunta puntual («¿cuál rige el día X?»), pero el histórico necesita la regla completa. Se escribe en un objeto del dominio, `NutritionPlanHistory`, que recibe los planes y deriva los periodos: sin Laravel, sin base de datos y con sus propios tests.

`ValidityPeriod` es un *value object* con las preguntas del negocio (¿rige tal día?, ¿había terminado?, ¿aún no ha empezado?) y una sola interpretación del último día, que es inclusivo.

En cambio, el **estado respecto a hoy** (`PlanStatus`) vive en la aplicación. Es una forma de presentar el periodo según la fecha del `Clock`, propia del listado, y no se guarda porque cambiaría cada día.

### El histórico devuelve agregados completos (CQS, ADR 0015)

`history()` devuelve `list<NutritionPlan>`, igual que `find()`. Se reconstruye cada plan entero solo para listarlo, pero una persona tiene unos pocos planes al año y se cargan en una sola consulta.

Un modelo de lectura propio (CQRS) evitaría leer los documentos `jsonb`, a cambio de un segundo puerto y otro adaptador que mantener. Queda anotado en el ADR para cuando el histórico crezca.

### El número único, como regla del contrato

Igual que «un inicio por día» (ADR 0011), «un número por plan» compara varios planes y la responde el repositorio al guardar:

- **en los dos adaptadores**, con sus tests de contrato, que fijan también qué error gana si un plan choca por las dos cosas: el del inicio;
- **en PostgreSQL**, con un índice único. El adaptador traduce su violación buscando qué plan ocupa ese día o ese número. Así no depende de qué índice compruebe antes la base de datos.

El índice va en una **migración nueva**, porque la de HEXM-0020 ya estaba publicada: editarla no tendría efecto en una base de datos que ya la hubiera aplicado. Si hubiera datos que incumplen la regla, la migración fallaría a propósito.

### Las consultas por HTTP

Siguen el patrón del recetario (ADR 0007 y 0008):

- `GET /api/nutrition-plans` devuelve el resumen de cada plan, con su `url`;
- `GET /api/nutrition-plans/{id}` devuelve el plan entero, con los platos en la misma forma que en `/api/menu`;
- un id mal formado da 422 (`InvalidNutritionPlanId`, del dominio) y uno inexistente, 404 (`PlanNotFound`), los dos por los manejadores globales.

Al existir ya la consulta de un plan, el alta devuelve por fin la cabecera `Location`.

## Alternativas descartadas

- **Un Laravel clásico**: una columna `ends_on` (o `is_active`) en la tabla, que se actualiza al registrar cada plan nuevo, y un *scope* `Plan::active()`. Es fácil que se desincronice: un plan registrado como histórico, una fecha corregida o dos procesos a la vez dejarían dos planes «activos» o ninguno. Aquí no hay nada que mantener sincronizado, porque el fin se deriva.
- **Calcular el periodo en el caso de uso o en el repositorio**: la regla 19 quedaría fuera del dominio, y el siguiente caso de uso que la necesitara (el asistente del nivel 11, por ejemplo) la repetiría.
- **Guardar el estado** («vigente», «finalizado»): cambiaría cada día sin que nadie tocara el plan.
- **Un puerto de lectura propio** para el listado (CQRS): descartado por ahora, como en el recetario (ADR 0008 y 0015).
- **Permitir números repetidos** y mejorar solo el mensaje: lo descartó el usuario al resolver el `domain_issue`, porque el histórico se lee por número.
- **Editar la migración de HEXM-0020** para añadir el índice: no surtiría efecto donde ya estuviera aplicada.

## Conceptos aplicados

| Concepto | Definición breve | Dónde verlo |
|---|---|---|
| Regla entre agregados en el dominio | Un objeto del dominio que recibe varios agregados y aplica la regla que ninguno puede aplicar solo. | `app-laravel/src/MealPlanning/Domain/NutritionPlanHistory.php` |
| *Value object* de periodo | Un rango de fechas con nombre del negocio y una sola interpretación de sus extremos. | `app-laravel/src/MealPlanning/Domain/ValidityPeriod.php` |
| Dato derivado, no guardado | El fin de la vigencia se calcula del plan siguiente; nunca queda desincronizado. | `NutritionPlanHistory::of()`, ADR 0010 |
| Estado derivado del tiempo | Vigente, finalizado o próximo según el `Clock`, calculado al consultar. | `app-laravel/src/MealPlanning/Application/PlanStatus.php`, `NutritionPlanSummaryView::fromPlan()` |
| Ampliar un puerto con su contrato | `history()` y la regla 22 entran en los tests de contrato y obligan a los dos adaptadores. | `app-laravel/tests/Feature/MealPlanning/Infrastructure/NutritionPlanRepositoryContractTest.php` |
| Regla de negocio en un índice único | PostgreSQL garantiza el número único; el adaptador la traduce a `DuplicatedPlanNumber`. | `app-laravel/src/MealPlanning/Infrastructure/Persistence/Eloquent/EloquentNutritionPlanRepository.php` |
| Evolución del esquema con migraciones nuevas | Cada cambio es una migración con su `up()` y `down()`; las publicadas no se editan. | `app-laravel/database/migrations/2026_11_05_000000_add_unique_number_to_nutrition_plans.php` |
| Consulta con DTO de lectura (CQS) | El agregado no sale de la aplicación; resumen para el listado y vista entera para un plan. | `NutritionPlanSummaryView`, `NutritionPlanView`, `GetNutritionPlan` |
| «No encontrado» como resultado | `PlanNotFound` (404) frente a `InvalidNutritionPlanId` (422). | `app-laravel/src/MealPlanning/Application/PlanNotFound.php` |

## Diagramas

- [¿Vigente, finalizado o próximo?](../diagramas/HEXM-0023/vigente-finalizado-o-proximo.workflow.json) (`workflow`): ¿cómo se decide el estado de cada plan del histórico? Muestra el orden por inicio, el periodo derivado del plan siguiente y las dos preguntas respecto a hoy que llevan a próximo, finalizado o vigente.
- [Las piezas del histórico](../diagramas/HEXM-0023/piezas-del-historico.class.json) (`class`): ¿qué clases intervienen y en qué capa? Muestra el puerto con `history()` y `DuplicatedPlanNumber`, `NutritionPlanHistory` y `ValidityPeriod` en el dominio, y las dos consultas con sus vistas, `PlanStatus` y `PlanNotFound`.

Para verlos: `cd app-laravel && composer docs:diagrams` y abrir `docs/diagramas/HEXM-0023/vigente-finalizado-o-proximo.html` y `docs/diagramas/HEXM-0023/piezas-del-historico.html` en el navegador.
