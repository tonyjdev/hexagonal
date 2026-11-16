# Nivel 07: Importador de planes

**Fecha:** 2026-11-16 · **Etiqueta git:** `nivel-07`

Tareas: HEXM-0028 (esqueleto del servicio `importer` en FastAPI), HEXM-0029 (leer el Excel de la nutricionista), HEXM-0030 (leer el Excel de la semana 2), HEXM-0031 (puerto `PlanReader` y adaptador HTTP), HEXM-0032 (importación pendiente de un plan) y HEXM-0033 (la API de la importación y la prueba de punta a punta). Lo acompañaron dos tareas transversales: HEXM-0034 (desarrollo local sin Docker) y HEXM-0035 (paso al sistema de tareas compartido).

## Qué se ha construido

- **El primer código Python del proyecto**, y el primer nivel del bloque B. Al empezar, un plan solo se podía dar de alta escribiendo su JSON a mano (`POST /api/nutrition-plans`). Al terminar, se sube el Excel de la nutricionista, después el de la semana 2, se confirma con la fecha de inicio y el plan responde a «¿qué me toca hoy?».
- **El servicio `services/importer`** (HEXM-0028 a 0030), en FastAPI:
  - gestionado con uv (`pyproject.toml`, `uv.lock`, `.venv/`), con ruff, mypy estricto y pytest detrás de `uv run poe check`, y un *src layout* con la lectura separada de HTTP (ADR 0021);
  - `POST /nutritionist-plans/read`: el Excel de la nutricionista con openpyxl, traducido a modelos Pydantic con la forma del alta de un plan (ADR 0022);
  - `POST /self-weeks/read`: el Excel de la semana 2, con la hoja «Detalle» como fuente y «Resumen» como comprobación (ADR 0023);
  - un Excel mal formado da 422 con **todos** los problemas, cada uno con su hoja y su celda.
- **El puerto `PlanReader`** (HEXM-0031) en `MealPlanning\Application`, con dos adaptadores que cumplen los mismos tests de contrato:
  - `HttpPlanReader`, con el cliente HTTP de Laravel: el 422 del servicio pasa a `UnreadableDocument` (422), y un servicio caído, lento o que rompe el contrato, a `PlanReaderUnavailable` (503, al log);
  - `InMemoryPlanReader`, el fake de los tests;
  - el **contrato versionado** en `contracts/importer` (JSON Schema y ejemplos reales), que genera Python y comprueba PHP (ADR 0024).
- **La importación pendiente** (HEXM-0032): el agregado `PlanImport`, aparte de `NutritionPlan`, que sigue exigiendo siempre las dos semanas. Tiene estados deducidos (pendiente de la semana 2, lista para confirmar, confirmada), cuatro casos de uso y su repositorio en memoria y en PostgreSQL. Al confirmarla, llama a `RegisterNutritionPlan`: el mismo plan que el alta manual (ADR 0025).
- **La API de la importación** (HEXM-0033): `POST /api/plan-imports`, `PUT …/self-week`, `PUT …/nutritionist-plan`, `POST …/confirm` y `GET /api/plan-imports/{id}`, sobre una única pieza nueva del núcleo, la consulta `GetPlanImport` (ADR 0026).
- **Una prueba de punta a punta** con el servicio Python real y los Excel ficticios (`composer test:e2e`), que se salta en la suite normal si el servicio no está levantado.
- **677 tests de Pest** en el gate (eran 564 al cerrar el nivel 6), más **81 de pytest** en el servicio.

```
 curl / cliente
   │  multipart (file)                         ┌──────── services/importer (Python) ───────┐
   ▼                                           │                                           │
 PlanDocumentRequest ─► StartPlanImport ─► PlanReader ─► HttpPlanReader ──HTTP──► FastAPI ─► openpyxl
 (forma: .xlsx)          │  (Application)    (puerto)    (adaptador)    ◄──JSON── Pydantic   │
                         ▼                                                └──────────────────┘
                     PlanImport ─► PlanImportRepository ─► PostgreSQL (plan_imports, jsonb)
                         │
   POST …/confirm ─► ConfirmPlanImport ─► RegisterNutritionPlan ─► NutritionPlan ─► GET /api/menu
                                          (mismas reglas que el alta manual)
```

## Conceptos aprendidos

- **Python para alguien que viene de PHP**: uv y el entorno virtual frente a Composer y `vendor/`; *type hints* con mypy estricto frente a los tipos de PHP y PHPStan; módulos y paquetes frente a namespaces y PSR-4.
- **Pydantic**: modelos que validan y serializan, el equivalente a juntar un DTO, una *Form Request* y un *API Resource*. Además generan el JSON Schema del contrato.
- **FastAPI**: rutas como funciones con tipos, `UploadFile` para el *multipart*, `create_app()` como fábrica de la aplicación y `TestClient` para probar sin levantar el servidor.
- **Funciones puras para traducir texto** (el «elegir N», «alimento (ración)»…), probadas sin abrir ningún Excel.
- **Un servicio externo es solo un adaptador detrás de un puerto**: el núcleo de Laravel no sabe que existe Python. Cambiar el lector por uno de PDF con un LLM (nivel 15) será otro adaptador.
- **Dos formatos detrás del mismo puerto**, y **traducir los errores del otro lado** a los del dominio: 422 si lo arregla el usuario corrigiendo el Excel, 503 si es un fallo del sistema.
- ***Contract testing* entre dos lenguajes**: un contrato versionado que genera un lado y comprueba el otro, para que los dos no puedan divergir en silencio.
- **Lo incompleto con nombre propio**: un agregado nuevo (`PlanImport`) en lugar de relajar el invariante de otro.
- **Subir ficheros como adaptador de entrada**: la *Form Request* traduce el `UploadedFile` de Laravel a un DTO de primitivos (`PlanDocument`); el núcleo nunca ve HTTP.
- **REST con subrecursos y acciones**: PUT para sustituir un documento (idempotente) y POST a una acción con nombre (`…/confirm`) para una operación de negocio que crea otro recurso.
- **La pirámide de tests**: cientos de tests rápidos con *fakes*, tests de contrato en medio y una única prueba de punta a punta, cara y aislada en su grupo.

## Decisiones tomadas (y por qué)

- **Desarrollo local sin Docker**: PostgreSQL 18 nativo con pgvector, preparado por `scripts/db-setup.sh` ([ADR 0020](../adr/0020-postgresql-nativo-en-desarrollo-local.md)).
- **Herramientas y estructura del servicio**: uv, poethepoet, ruff, mypy estricto, *src layout*, y sin hexagonal completa dentro de un servicio tan pequeño, pero con la lectura separada de HTTP ([ADR 0021](../adr/0021-servicio-importer-herramientas-y-estructura.md)).
- **Leer el Excel de la nutricionista** con la forma del contrato del alta, descartando la columna «Momento» ([ADR 0022](../adr/0022-lectura-del-excel-de-la-nutricionista.md)).
- **Leer el Excel de la semana 2** con «Detalle» como fuente, «Resumen» como comprobación y los ingredientes con su texto entero: separar la cantidad es trabajo de un LLM (nivel 9) ([ADR 0023](../adr/0023-lectura-del-excel-de-la-semana-2.md)).
- **El puerto `PlanReader` devuelve DTO de primitivos, no el agregado** (un documento solo no es un plan), y el contrato entre Python y PHP se versiona en `contracts/importer` ([ADR 0024](../adr/0024-puerto-planreader-y-contrato-con-el-importador.md)).
- **La importación pendiente es un agregado propio**, con estados deducidos y confirmación a través de `RegisterNutritionPlan` ([ADR 0025](../adr/0025-importacion-pendiente-de-un-plan.md)).
- **La API de la importación** como un recurso con subrecursos (PUT) y una acción (`POST …/confirm`), y **la prueba de punta a punta** que se salta en la suite normal y falla cuando se pide expresamente ([ADR 0026](../adr/0026-api-de-la-importacion-y-prueba-de-punta-a-punta.md)).

## Dificultades / dudas

- **El alcance del nivel cambió al empezarlo** (HEXM-0028): al principio solo se iba a importar la semana 1. Al mirar los documentos reales quedó claro que la semana 2 también llega en un Excel, y el nivel pasó a leer los dos y juntarlos en una importación pendiente. El nivel 8 se quedó con hacer cumplir las reglas de los intercambios.
- **Los Excel reales no se versionan** (son datos de salud): los tests construyen Excel ficticios con openpyxl, y los ejemplos del contrato salen de ellos.
- **PUT con ficheros**: históricamente PHP solo procesaba `multipart/form-data` en POST. Con PHP 8.4, Symfony usa `request_parse_body()` y funciona también en PUT. Los tests de Laravel no lo habrían detectado, porque no pasan por ese código de PHP, así que se comprobó con `curl` contra `php artisan serve` (HEXM-0033).
- **Una prueba de punta a punta que no se ejecuta sola**: sin CI, depende de que alguien levante el servicio. Se aceptó: dentro del gate se salta, y `composer test:e2e` falla si el servicio no responde.
- **Pendiente:**
  - los mensajes de validación de forma en inglés (deuda de HEXM-0022);
  - actualizar Laravel y Symfony por los avisos de `composer audit` (HEXM-0036);
  - un CI que levante el servicio y ejecute siempre la prueba de punta a punta;
  - el siguiente nivel: la semana 2 desde la app y las reglas de los intercambios como código (nivel 8).

## Para profundizar

- La documentación de uv (proyectos, `uv.lock`, `uv run`) y de FastAPI (*Request Files*, *Testing*).
- La documentación de Pydantic v2: modelos, validadores y `model_json_schema()`.
- Martin Fowler, *ContractTest* y *The Practical Test Pyramid* (Ham Vocke), en martinfowler.com.
- Alistair Cockburn, *Hexagonal Architecture*: el ejemplo de cambiar un adaptador sin tocar la aplicación.
- La RFC 9110 (semántica de HTTP): métodos idempotentes, PUT frente a POST y el código 201 con `Location`.
- El RFC de PHP 8.4 *`request_parse_body()`*.
