# Nivel 05: Planes (adaptadores)

**Fecha:** 2026-11-05 · **Etiqueta git:** `nivel-05`

Tareas: HEXM-0020 (repositorio Eloquent de planes), HEXM-0021 (alta manual de un plan por API), HEXM-0022 (menú de hoy por API y reloj real) y HEXM-0023 (histórico de planes).

## Qué se ha construido

- **Los planes, de verdad.** Al empezar el nivel, un plan solo existía dentro de un test. Al terminarlo, se da de alta por la API, se guarda en PostgreSQL, se consulta «qué me toca hoy» con el reloj real y se conserva el histórico.
- **La persistencia** (HEXM-0020): `EloquentNutritionPlanRepository`, segundo adaptador del puerto.
  - Una fila por plan con el contenido en documentos `jsonb`.
  - `NutritionPlanMapper` lee el JSON como entrada no confiable.
  - Un índice único en `starts_on` y la transacción como `SAVEPOINT`, para que un duplicado no aborte la transacción de fuera.
- **El alta** (HEXM-0021): `RegisterNutritionPlan`, el primer comando del contexto.
  - `POST /api/nutrition-plans`, con un JSON en el formato de los documentos: menús 1-6 y días 8-14.
  - Una *Form Request* que solo valida la forma.
  - `MealPlanningServiceProvider`, la raíz de composición del contexto.
- **«¿Qué me toca hoy?» por HTTP** (HEXM-0022): `GET /api/menu[?date=]`, sin tocar el núcleo.
  - `SharedServiceProvider` enchufa el reloj real (`SystemClock`) con la zona de `config/clock.php`.
  - En los tests, «hoy» se fija sustituyendo el puerto en el contenedor.
- **El histórico** (HEXM-0023):
  - `ValidityPeriod` y `NutritionPlanHistory` derivan en el dominio la vigencia de cada plan;
  - el puerto gana `history()` y la regla del número único (`DuplicatedPlanNumber`);
  - `ListNutritionPlans` y `GetNutritionPlan` se publican con `GET /api/nutrition-plans[/{id}]`.
- **480 tests** en el gate (eran 381 al cerrar el nivel 4).

```
POST /api/nutrition-plans ─► RegisterNutritionPlan ─┐
GET  /api/menu            ─► GetMenuForDate ────────┼─► NutritionPlanRepository ─► EloquentNutritionPlanRepository ─► nutrition_plans (jsonb)
GET  /api/nutrition-plans ─► ListNutritionPlans ────┤        (save · find · inForceOn · history)
GET  /api/nutrition-plans/{id} ─► GetNutritionPlan ─┘
                   Clock ─► SystemClock (Europe/Madrid)   ·   raíces: Shared, MealPlanning y Recipes ServiceProvider
```

## Conceptos aprendidos

- **El agregado como documento.** Si el dominio usa algo siempre como una unidad, se puede guardar como una unidad. El recetario eligió tablas y los planes, `jsonb`, por razones contrarias (ADR 0006 frente al 0013).
- **El JSON guardado es una entrada no confiable.** Lectura defensiva con la ruta del dato corrupto, y reconstrucción con los constructores del dominio.
- **Reglas de negocio en índices únicos.** La regla 5 (un inicio por día) y la 22 (un número por plan) las garantiza PostgreSQL, también con concurrencia, y el adaptador las traduce a errores del dominio.
- **Transacciones abortadas y `SAVEPOINT` en PostgreSQL.** La transacción no estaba para la atomicidad (una fila ya lo es), sino para sobrevivir a un error. Lo demostró un experimento.
- **El contrato de una API que copia el papel.** La traducción a días de la semana se queda en el dominio. Es también el contrato del importador del nivel 7.
- **Validación de forma frente a reglas** en un payload grande, y **errores de traducción** como errores del dominio.
- **Raíces de composición**: una por contexto, más una compartida para los puertos de `Shared`.
- **La zona horaria como decisión de despliegue**, y **sustituir un puerto en el contenedor** en lugar de usar relojes globales.
- **Estados y periodos derivados.** La vigencia y el «vigente/finalizado/próximo» se calculan, no se guardan.
- **Evolucionar el esquema con migraciones nuevas**, nunca editando las ya publicadas.
- **Ampliar un puerto con su contrato.** `history()` y el número único entraron en los tests de contrato, y los dos adaptadores tuvieron que cumplirlos.

## Decisiones tomadas (y por qué)

- **Una fila por plan con `jsonb`**, porque el agregado siempre se lee y se escribe entero ([ADR 0013](../adr/0013-persistencia-del-agregado-nutritionplan-como-documento.md)).
- **El JSON de entrada con el formato de los documentos**, porque quien da de alta el plan copia el papel ([ADR 0014](../adr/0014-contrato-de-entrada-de-un-plan-con-el-formato-de-los-documentos.md)).
- **El reloj en `SharedServiceProvider`**, con la zona en `config/clock.php` y `app.timezone` en UTC (actualización del [ADR 0012](../adr/0012-el-reloj-como-puerto-de-aplicacion.md)).
- **El histórico devuelve agregados completos** (CQS y no CQRS), con **la vigencia en el dominio** y el estado respecto a hoy en la consulta ([ADR 0015](../adr/0015-historico-de-planes-y-numero-unico.md)).
- **Número de plan único**, que resuelve el `domain_issue` de HEXM-0021. Si un plan choca también por el inicio, gana `DuplicatedPlanStart` (ADR 0015).

## Dificultades / dudas

- **El experimento de la transacción** (HEXM-0020). Sin ella, el `INSERT` duplicado seguía funcionando, porque `createOrFirst()` de Laravel ya usa un *savepoint*. El `UPDATE` de un plan movido a un día ocupado, en cambio, fallaba con `SQLSTATE[25P02]`. Ese caso no estaba en el contrato y se añadió.
- **`Request::date()` ya existe en Laravel** (HEXM-0022): el método de la *Form Request* tuvo que llamarse `requestedDate()`.
- **La legibilidad de los diagramas**. El primer `dataflow` (HEXM-0021) necesitó varias rondas: textos más cortos y etiquetas recolocadas, porque las cinco etapas fijan un ancho mínimo.
- **Un `cat > fichero` sin entrada** dejó colgada una ejecución (HEXM-0022). La lección: escribir los ficheros con su contenido, nunca abrir una redirección vacía.
- **Pendiente:**
  - los mensajes de validación de forma salen en inglés (`tech_debt` de HEXM-0022);
  - una semana que cruza el cambio de plan, o un plan que termina sin que empiece otro (ADR 0010, 0011);
  - el siguiente nivel, la relación de los platos con las recetas (nivel 6).

## Para profundizar

- La documentación de PostgreSQL sobre `jsonb` (operadores, índices GIN) y sobre `SAVEPOINT` y el estado de las transacciones abortadas.
- Mark Seemann, *Dependency Injection Principles, Practices, and Patterns*: la raíz de composición.
- Martin Fowler, *CQRS* y *Data Mapper* (martinfowler.com), para cuando el histórico necesite un modelo de lectura propio.
- La documentación de Laravel sobre *API Resources*, `createOrFirst()` y el contenedor de servicios (`instance`, `singleton`).
