# 0026. API de la importación y prueba de punta a punta

**Fecha:** 2026-11-16 · **Estado:** aceptada · **Tarea:** HEXM-0033 · **Relacionado:** [ADR 0007](0007-adaptador-http-validacion-de-forma-y-errores-de-dominio.md), [ADR 0008](0008-consultas-dto-de-lectura-y-no-encontrado.md), [ADR 0014](0014-contrato-de-entrada-de-un-plan-con-el-formato-de-los-documentos.md), [ADR 0024](0024-puerto-planreader-y-contrato-con-el-importador.md), [ADR 0025](0025-importacion-pendiente-de-un-plan.md)

## Contexto

Al terminar HEXM-0032, el núcleo ya sabía importar un plan desde sus dos Excel: `StartPlanImport`, `ReplaceImportedNutritionistPlan`, `AttachSelfWeekToPlanImport` y `ConfirmPlanImport`, con el puerto `PlanReader` (ADR 0024) y el agregado `PlanImport` (ADR 0025). Pero nadie de fuera podía usarlo. Faltaban:

1. **la puerta de entrada HTTP**: recibir ficheros, la fecha de inicio y una forma de preguntar cómo va una importación;
2. **una prueba de que el sistema entero funciona**: Laravel y el servicio Python, cada uno en su proceso, con los Excel de verdad. Hasta ahora, cada lado se probaba por separado, unido solo por el contrato versionado de `contracts/importer`.

## Decisión

### 1. Un recurso, `plan-imports`, con subrecursos para los documentos y una acción para confirmar

| Petición | Caso de uso | Respuesta |
|---|---|---|
| `POST /api/plan-imports` (`file`) | `StartPlanImport` | 201 + `Location`, la importación |
| `GET /api/plan-imports/{id}` | `GetPlanImport` (nuevo) | 200, la importación |
| `PUT /api/plan-imports/{id}/self-week` (`file`) | `AttachSelfWeekToPlanImport` | 200, la importación |
| `PUT /api/plan-imports/{id}/nutritionist-plan` (`file`) | `ReplaceImportedNutritionistPlan` | 200, la importación |
| `POST /api/plan-imports/{id}/confirm` (`starts_on`) | `ConfirmPlanImport` | 201 + `Location` del plan, `{"id": …}` |

- **Los documentos son subrecursos y se suben con PUT.** Una importación tiene como mucho un Excel de cada tipo, y subir otro lo sustituye. Esa es la semántica de PUT («deja este recurso así»), que además es idempotente. Un POST diría «añade otro», que no es lo que pasa.
- **Los ficheros llegan como `multipart/form-data`, en el campo `file`**, igual que en el servicio importer. En PUT funciona porque, desde PHP 8.4, Symfony lee el cuerpo con `request_parse_body()` en PUT, PATCH y DELETE (antes solo se procesaba en POST, y el truco era un POST con `_method=PUT`). Se comprobó con `curl` contra `php artisan serve`, no solo con los tests de Laravel, que no pasan por ese código de PHP.
- **Confirmar es una acción (`POST …/confirm`), no un `PATCH {"status": "confirmed"}`.** El estado de una importación no se guarda (se deduce, ADR 0025), y confirmar es una operación de negocio que crea otro recurso, el plan. Su respuesta es la del alta manual (`POST /api/nutrition-plans`), porque por debajo es el mismo caso de uso, `RegisterNutritionPlan`.
- **La fecha se llama `starts_on`**, como en el alta manual (ADR 0014).
- **Las subidas responden con la importación entera** (número, estado, si tiene semana 2 y, una vez confirmada, el plan), y no solo con el id. Quien sube un Excel quiere saber enseguida si se ha leído y qué le falta. El controlador la obtiene llamando a `GetPlanImport` después del comando: el comando no devuelve el DTO, para no mezclar escribir y leer (CQS).
- **Una única pieza nueva en el núcleo, `GetPlanImport`**, con su DTO `PlanImportView` (ADR 0008). No lleva el contenido de los Excel: eso se ve en el plan, una vez confirmado.

### 2. Validación de forma en las *Form Requests*; el contenido lo juzgan el importador y el dominio

- `PlanDocumentRequest`, común a las tres subidas, pide un fichero con extensión `.xlsx` y como mucho 5 MB. Usa `extensions` y no `mimes`, porque un `.xlsx` es un ZIP por dentro y, según la versión de libmagic, se detecta como `application/zip`. Si el Excel tiene las hojas y las celdas esperadas lo dice el servicio (`UnreadableDocument`, 422 con cada problema).
- `ConfirmPlanImportRequest` pide `starts_on` con el formato `AAAA-MM-DD`. Que sea lunes, o que el número y el inicio no se repitan, lo decide `RegisterNutritionPlan`.
- Los códigos de error no son nuevos: los traduce `bootstrap/app.php` desde las clases base (`DomainException` → 422, `NotFound` → 404, `ServiceUnavailable` → 503).

### 3. La pirámide de tests: el fake en la suite normal y una única prueba de punta a punta

- **La suite normal usa el fake.** `PlanImportEndpointsTest` sustituye `PlanReader` por `InMemoryPlanReader` en el contenedor, con las lecturas del contrato versionado. No necesita el servicio Python y cubre todos los casos: el camino feliz con el menú de las dos semanas, la forma de la petición, cada error y su código.
- **Una prueba de punta a punta con el servicio real**, `PlanImportEndToEndTest`, en el grupo `e2e`. Usa el `HttpPlanReader` de verdad y los Excel ficticios del servicio (`services/importer/tests/fixtures`). Tiene dos tests: el camino feliz y el Excel equivocado.
  - Antes de cada test pregunta a `GET /health` con un *timeout* de 1 s. Si el servicio no responde, **se salta**: la suite normal (y el gate) no depende de que haya un proceso Python levantado, y cuando sí lo hay, la prueba se ejecuta.
  - `composer test:e2e` ejecuta solo ese grupo y pone `IMPORTER_E2E_REQUIRED=1`. Ahí, si el servicio no responde, el test **falla**: quien pide expresamente la prueba de punta a punta no quiere un verde que no ha probado nada.

## Consecuencias

- **Positivas**
  - El usuario ya puede importar un plan entero por la API, y el plan resultante responde a «¿qué me toca hoy?». El nivel 7 queda cerrado.
  - El núcleo apenas ha cambiado: una consulta. Todo lo demás son adaptadores de entrada. Es la promesa de la hexagonal: el mismo núcleo, otra puerta.
  - La importación y el alta manual comparten caso de uso, reglas, nombre de la fecha y respuesta.
  - Hay una prueba de que Laravel y Python encajan de verdad, sin hacer la suite normal lenta ni frágil.
- **Negativas / riesgos**
  - La prueba de punta a punta solo se ejecuta si alguien levanta el servicio. Sin CI, depende de la disciplina de lanzar `composer test:e2e` antes de cerrar una tarea que toque el importador. Cuando haya CI, puede levantar el servicio y ejecutarla siempre.
  - Se salta en silencio dentro de `composer ci:check`. La salida de Pest lo muestra como *skipped*, pero es fácil no verlo.
  - La prueba de punta a punta usa la base de datos de los tests (`hexagonal_test`), así que, como todo el gate, no se puede ejecutar a la vez desde dos *worktrees*.
  - Los mensajes de validación de forma («The file field must be a file of type: xlsx») siguen en inglés, como en el resto de la API: la deuda que anotó HEXM-0022 la paga HEXM-0037.
