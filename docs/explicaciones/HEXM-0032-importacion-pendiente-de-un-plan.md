# Explicación HEXM-0032: Importación pendiente de un plan

## Explicación

Un plan nutricional llega en **dos documentos y en dos momentos**. La nutricionista entrega su Excel (número, pautas fijas, intercambios y semana 1) y, días después, el usuario elabora el Excel de la semana 2. Desde HEXM-0031 Laravel ya sabe leer cada uno con el puerto `PlanReader`, pero faltaba **dónde vive lo leído** mientras todavía no es un plan. Un `NutritionPlan` exige las dos semanas (regla 1), y la fecha de inicio no viene en ningún Excel.

Esta tarea crea la **importación pendiente**: un agregado nuevo, `PlanImport`, que empieza con el Excel de la nutricionista, recibe después el de la semana 2 y, con los dos y la fecha de inicio, se **confirma** como un `NutritionPlan`. Confirmar no construye el plan por su cuenta: compone el mismo comando que el alta manual y llama al mismo caso de uso, `RegisterNutritionPlan`. Así el plan importado pasa por exactamente las mismas reglas. La API HTTP de la importación queda para HEXM-0033.

## Lógica de negocio

Las reglas son decisiones de negocio tomadas al empezar la tarea (reglas 25-28 de `DOMINIO.md`):

- **Empieza siempre con el Excel de la nutricionista.** Por ejemplo, se sube `plan-99.xlsx` y nace una importación del plan 99, en estado *pendiente de la semana 2*.
- **Con la semana 2 queda lista para confirmar.** Se sube `semana-2.xlsx`, con los días 8 a 14 y el 13 vacío.
- **Se pueden volver a subir los dos Excel** mientras está pendiente: si la nutricionista corrige un intercambio, se sube otra vez su Excel y la semana 2 ya subida se conserva. Volver a subir la semana 2 la sustituye.
- **No se confirma sin la semana 2**: «La importación del plan 99 no se puede confirmar: falta el Excel de la semana 2.» No se crea ningún plan.
- **Al confirmar se aplican todas las reglas del plan.** Confirmar con el martes 03/11/2026 da «Un plan empieza siempre en lunes, y el 03/11/2026 es martes.». Si ya existe un plan 99, da `DuplicatedPlanNumber`. En los dos casos no se crea ningún plan y la importación sigue lista para confirmar.
- **Confirmada, no cambia**: guarda el id del plan que se creó, y cambiarla o confirmarla otra vez da «La importación del plan 99 ya está confirmada: no se puede cambiar ni volver a confirmar.».
- **Puede haber varias importaciones pendientes a la vez**, sin reglas entre ellas.

El estado (*pendiente de la semana 2*, *lista para confirmar*, *confirmada*) no se guarda en el agregado: se **deduce** de lo que tiene. Si tiene plan, está confirmada; si tiene semana 2, está lista; si no, espera la semana 2.

## Decisiones de arquitectura

### Por qué cada pieza en su capa

- **Dominio (`MealPlanning\Domain`)**: `PlanImport` (raíz de agregado, inmutable), `PlanImportId`, `PlanImportStatus`, el contenido de cada Excel (`ImportedNutritionistPlan`, `ImportedSelfWeek`), la excepción `InvalidPlanImport` y el puerto `PlanImportRepository`. Las reglas del ciclo de vida son del negocio y no dependen de nada externo.
- **Aplicación (`MealPlanning\Application`)**: los cuatro casos de uso (`StartPlanImport`, `ReplaceImportedNutritionistPlan`, `AttachSelfWeekToPlanImport`, `ConfirmPlanImport`), `PlanImportNotFound` (404) e `ImportedDocuments`. Esta última traduce las lecturas del puerto `PlanReader`, que son DTO de esta capa, a los value objects del dominio, que no puede depender de ellas.
- **Infraestructura**: `InMemoryPlanImportRepository` para los tests y `EloquentPlanImportRepository`, con `PlanImportMapper` y `PlanImportModel`, sobre la tabla `plan_imports`. El *binding* está en `MealPlanningServiceProvider`. Las funciones de lectura defensiva del JSON pasan de `NutritionPlanMapper` al *trait* `ReadsStoredJson`, que comparten los dos *mappers*.

### El sentido de las dependencias

Las flechas siguen apuntando hacia dentro. Los casos de uso conocen dos puertos de salida (`PlanReader` para leer, `PlanImportRepository` para guardar) y otro caso de uso (`RegisterNutritionPlan`). El dominio no conoce ninguno de los tres. `ConfirmPlanImport` recibe `RegisterNutritionPlan` por el constructor, como si fuera un puerto: el contenedor de Laravel lo construye con sus propias dependencias, y los tests lo montan a mano con los repositorios en memoria.

### El recorrido de una confirmación

1. `ConfirmPlanImport(id, lunes)` busca la importación (si no existe, `PlanImportNotFound`).
2. Pregunta al agregado si se puede confirmar con `selfWeekForConfirmation()`, que devuelve la semana 2 o lanza `InvalidPlanImport`.
3. Copia el contenido de los dos Excel y la fecha a un `RegisterNutritionPlanCommand`. No hay que traducir nada, porque el contenido ya tiene la forma del contrato del alta (ADR 0014).
4. `RegisterNutritionPlan` construye el plan (`NutritionPlan::create()`, que comprueba el lunes y las dos semanas), comprueba las recetas y lo guarda. El repositorio de planes vigila el número y el inicio únicos.
5. Con el id del plan, la importación se marca con `confirm(planId)` y se guarda.

### Dos agregados, dos guardados

Confirmar cambia dos agregados. Se sigue la regla práctica de DDD de «un agregado por transacción»: cada uno se guarda en su repositorio, el plan primero. Si algo falla antes de guardar el plan, no se guarda nada. Si fallara el segundo guardado, el plan existiría y la importación seguiría pendiente; confirmarla otra vez daría `DuplicatedPlanNumber`, así que nunca habría dos planes iguales. Es un riesgo aceptado y documentado en el ADR 0025, antes que meter un puerto de transacciones en el núcleo.

### Persistencia

Una fila por importación en `plan_imports`, con el contenido de cada Excel como documento `jsonb`, como los planes (ADR 0013). El `status` se guarda también como columna, para leer la tabla a simple vista, con un `CHECK` que impide filas incoherentes (una «confirmada» sin plan). No hay clave foránea hacia `nutrition_plans`, porque es una referencia entre agregados por su id (ADR 0016).

## Alternativas descartadas

- **Relajar `NutritionPlan` para que admita solo la semana 1.** Es lo que se haría en un Laravel clásico: una columna `status` en `nutrition_plans` y `week_2` nullable. Rompería la invariante principal del plan y obligaría al calendario, al histórico y a la API a preguntar en todas partes si el plan está completo.
- **Guardar en la importación objetos del dominio (`MenuWeek`, `FixedMeal`).** Detectaría antes un menú que falta, pero habría dos traducciones del documento al dominio que mantener iguales, y el plan importado podría acabar distinto del manual. Guardando los datos con la forma del contrato, confirmar es copiar.
- **Un campo `$status` en el agregado.** Podría contradecir a los datos (una «confirmada» sin plan). Deducirlo lo hace imposible.
- **Un puerto de transacciones para que confirmar sea atómico.** Añade una abstracción al núcleo para un fallo muy improbable en una aplicación de un solo usuario, que además ya está acotado por el número único.
- **Casos de uso separados para añadir y para sustituir la semana 2.** Para el usuario es el mismo gesto, y obligaría a saber de antemano si ya había una.
- **Que `PlanImport` reutilice `NutritionistPlanReading`.** Son DTO de `Application`, y el dominio no puede depender de ellos (lo comprueban los tests de arquitectura).

## Conceptos aplicados

| Concepto | Definición breve | Dónde verlo |
|---|---|---|
| Proceso como agregado propio | Un proceso de varios pasos se modela con su propio agregado, sin debilitar el agregado final | `app-laravel/src/MealPlanning/Domain/PlanImport.php` |
| Ciclo de vida (*lifecycle*) | Estados y transiciones con nombre; lo que no está permitido es un error del negocio | `PlanImportStatus.php`, `PlanImport.php`, `InvalidPlanImport.php` |
| Estado derivado | El estado se calcula de los datos, no se guarda dos veces | `PlanImport::status()` |
| Entidad inmutable | Cada transición devuelve una versión nueva con el mismo id | `PlanImport.php` |
| Reutilizar un caso de uso por su comando | Confirmar compone el comando del alta y llama al mismo caso de uso | `app-laravel/src/MealPlanning/Application/ConfirmPlanImport.php` |
| Un agregado por transacción | Cada agregado se guarda en su repositorio; se acepta y documenta el hueco entre los dos | `ConfirmPlanImport.php`, ADR 0025 |
| Traducción entre capas | Los DTO de un puerto de `Application` se convierten en value objects del dominio | `ImportedDocuments.php` |
| Repositorio y tests de contrato | Un puerto por agregado y la misma batería contra los dos adaptadores | `PlanImportRepository.php`, `tests/Feature/MealPlanning/Infrastructure/PlanImportRepositoryContractTest.php` |
| Agregado como documento `jsonb` con `CHECK` | Una fila por agregado; la base de datos repite las invariantes básicas | `database/migrations/2026_11_16_000000_create_plan_imports_table.php`, `PlanImportMapper.php` |
| *Trait* para compartir código | Funciones comunes a dos clases `final` sin una clase base artificial | `Persistence/Eloquent/ReadsStoredJson.php` |
| Object Mother con datos del contrato | Ejemplos de prueba construidos con respuestas reales del servicio | `tests/Support/MealPlanning/PlanImportMother.php` |

## Diagramas

- [Ciclo de vida de una importación de plan](../diagramas/HEXM-0032/ciclo-de-vida-de-la-importacion.lifecycle.json) (`lifecycle`): los tres estados, cómo se pasa de uno a otro, los dos rechazos de `InvalidPlanImport` y el `NutritionPlan` que se crea antes de `confirm()`.
- [Confirmar una importación](../diagramas/HEXM-0032/confirmar-una-importacion.sequence.json) (`sequence`): `ConfirmPlanImport` en sus tres caminos: el plan creado y la importación confirmada, el rechazo sin semana 2 (sin intentar crear el plan) y el rechazo del alta por empezar en martes (sin guardar nada).

Se ven con `~/.agents/workspace-kit/bin/diagrams.sh HEXM-0032`, que genera los HTML en `~/.cache/workspace-kit/diagramas/hexagonal/HEXM-0032/`.
