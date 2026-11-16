# Explicación HEXM-0033: Importar un plan por la API y cerrar el nivel 7

## Explicación

Al terminar HEXM-0032, el núcleo ya sabía importar un plan desde sus dos Excel. Los cuatro casos de uso existían, el puerto `PlanReader` hablaba con el servicio Python y el agregado `PlanImport` se guardaba en PostgreSQL. Pero era una casa sin puerta: nadie de fuera podía pedírselo.

Esta tarea pone la puerta (la API HTTP de la importación) y comprueba que el sistema entero funciona de verdad: Laravel y Python en sus dos procesos, con los Excel ficticios, hasta que el plan importado responde a «¿qué me toca hoy?». Con eso se cierra el nivel 7, el primero del bloque de Python.

Lo más interesante es lo poco que cambia el núcleo: una consulta nueva (`GetPlanImport`). Todo lo demás son adaptadores de entrada y tests. Es la promesa de la arquitectura hexagonal hecha realidad: el mismo núcleo, otra puerta.

## Lógica de negocio

No hay reglas nuevas: la API solo publica las que ya existían (reglas 25 a 28 de `docs/DOMINIO.md`). Recorrido con el plan ficticio 99:

1. **El usuario sube el Excel de la nutricionista** (`POST /api/plan-imports`). La importación queda **pendiente de la semana 2**, con el número que trae el Excel (99).
2. **Sube el Excel de la semana 2** (`PUT /api/plan-imports/{id}/self-week`). La importación queda **lista para confirmar**. Si se equivoca, puede volver a subir cualquiera de los dos (regla 27); sustituir el de la nutricionista conserva la semana 2.
3. **Confirma con la fecha de inicio** (`POST /api/plan-imports/{id}/confirm`, `{"starts_on": "2026-11-02"}`). Se crea el plan con las mismas reglas que el alta manual: tiene que empezar en lunes, y no puede repetir el número ni el inicio de otro plan.
4. **El plan responde.** El martes 03/11 (semana 1) toca el menú 2 de la nutricionista, «Arroz (1/3 vaso en crudo) con verduras y pollo (2 filetes)». El lunes 09/11 (semana 2) toca el día 8 del usuario, «Lentejas estofadas». El sábado 14/11 es libre.

En cualquier momento, `GET /api/plan-imports/{id}` dice cómo va y qué le falta.

Cuándo se rechaza, y con qué código:

| Situación | Código | Quién lo decide |
|---|---|---|
| No llega un fichero, no es `.xlsx` o pasa de 5 MB | 422 (`errors.file`) | La *Form Request* (forma) |
| La fecha no es `AAAA-MM-DD` | 422 (`errors.starts_on`) | La *Form Request* (forma) |
| El Excel no tiene las hojas y celdas esperadas | 422, cada problema en español con su hoja y su celda | El servicio importer → `UnreadableDocument` |
| Confirmar sin la semana 2 | 422, «La importación del plan 99 no se puede confirmar: falta el Excel de la semana 2.» | `PlanImport` (dominio) |
| La fecha no es lunes | 422, «Un plan empieza siempre en lunes, y el 03/11/2026 es martes.» | `NutritionPlan` (dominio) |
| La importación no existe | 404 | `PlanImportNotFound` |
| El servicio importer no responde | 503, y al log | `PlanReaderUnavailable` (HEXM-0031) |

## Decisiones de arquitectura

### Por qué cada pieza en su capa

- **`GetPlanImport` y `PlanImportView`, en `MealPlanning\Application`.** Responder «¿cómo va?» es un caso de uso de consulta, como `GetNutritionPlan`. Recibe el id como texto y devuelve un DTO de primitivos, nunca el agregado (ADR 0008). La vista no lleva el contenido de los Excel: eso se ve en el plan, una vez confirmado.
- **Los controladores, las *Form Requests* y el `PlanImportResource`, en `MealPlanning\Infrastructure\Http`.** Son adaptadores de entrada (*driving*): traducen HTTP a una llamada al caso de uso y su resultado otra vez a HTTP. No tienen lógica ni `try/catch`, porque los errores los traduce `bootstrap/app.php` desde las clases base (ADR 0007).
- **`PlanDocumentRequest::toDocument()` es la frontera.** Convierte el `UploadedFile` (una clase de Symfony y Laravel) en un `PlanDocument` (nombre y contenido). A partir de ahí, ni los casos de uso ni el puerto saben que hubo una petición HTTP.
- **La prueba de punta a punta, en `tests/Feature`, con el grupo `e2e`.** No es código de la aplicación: es una forma de comprobar que las piezas encajan.

### El sentido de las dependencias

```
HTTP ─► PlanDocumentRequest ─► StartPlanImport ─► PlanReader ◄── HttpPlanReader ─► importer (Python)
        (Infrastructure)       (Application)      (puerto)       (Infrastructure)
```

Las flechas siguen yendo hacia dentro. El controlador conoce el caso de uso; el caso de uso conoce el puerto, pero no el adaptador. `HttpPlanReader` lo enchufa `MealPlanningServiceProvider`, y los tests lo sustituyen en el contenedor por `InMemoryPlanReader` sin tocar ni una línea del núcleo.

### El recorrido de una petición

`PUT /api/plan-imports/{id}/self-week` con el Excel de la semana 2:

1. Laravel construye la `Request`. Con PHP 8.4, Symfony lee el cuerpo `multipart/form-data` también en PUT (`request_parse_body()`).
2. La ruta lleva a `AttachSelfWeekController`, y Laravel valida antes `PlanDocumentRequest`: un fichero `.xlsx` de hasta 5 MB.
3. El controlador llama a `AttachSelfWeekToPlanImport($id, $request->toDocument())`.
4. El caso de uso busca la importación (si no existe, 404 **antes** de leer nada) y llama a `PlanReader::readSelfWeek()`.
5. `HttpPlanReader` sube el fichero a `POST /self-weeks/read` del servicio Python, que lo lee con openpyxl y responde el JSON del contrato.
6. El caso de uso guarda la importación nueva (`attachSelfWeek()`, inmutable) en `plan_imports`.
7. El controlador consulta `GetPlanImport` y responde `PlanImportResource`: 200, «lista para confirmar».

### La forma de la API

- **Un recurso, `plan-imports`.** Los documentos son **subrecursos** y se suben con **PUT**: hay como mucho uno de cada tipo y subir otro lo sustituye, que es justo lo que significa PUT (idempotente).
- **Confirmar es una acción**, `POST …/confirm`, porque es una operación de negocio que crea otro recurso (el plan). Su respuesta es la del alta manual (201, `{"id"}` y `Location` del plan), porque por debajo es el mismo caso de uso, `RegisterNutritionPlan`. La fecha se llama `starts_on`, como en el alta.
- **Las subidas responden con la importación entera** (número, estado, si tiene semana 2): es lo primero que quiere saber quien sube un Excel. El controlador la obtiene con `GetPlanImport` después del comando, y el comando sigue devolviendo solo el id (CQS).

### La pirámide de tests

- **Abajo, la suite normal**: 20 tests de los endpoints con el fake `InMemoryPlanReader`, más los unitarios de la consulta. Cubren todos los casos, sin Python y en milisegundos.
- **En medio, el contrato** (HEXM-0031): el JSON de los dos lados contra `contracts/importer`.
- **Arriba, una única prueba de punta a punta** con el servicio real, `PlanImportEndToEndTest`: el camino feliz y el Excel equivocado.
  - Antes de cada test pregunta a `GET /health` con un *timeout* de 1 s. Dentro de la suite normal (y del gate), si el servicio no responde, **se salta**; si responde, se ejecuta.
  - `composer test:e2e` ejecuta solo ese grupo con `IMPORTER_E2E_REQUIRED=1`, y entonces, sin servicio, **falla**: quien la pide no quiere un verde que no ha probado nada.

## Alternativas descartadas

- **Cómo se haría en un Laravel clásico:** un controlador que recibe el fichero, llama a `Http::attach()->post()` al servicio, recorre el JSON y crea los modelos Eloquent, todo en el mismo método, con las reglas repartidas entre la validación y el controlador. Aquí eso son cuatro capas, y por eso esta tarea solo ha tenido que añadir la puerta: el resto ya estaba probado por separado.
- **`POST` para los documentos (`POST …/self-week`).** Diría «añade otro documento», y no es lo que pasa: se sustituye. Además, ya no hace falta el truco de un POST con `_method=PUT` para subir ficheros.
- **`PATCH /api/plan-imports/{id}` con `{"status": "confirmed"}`.** Dejaría al cliente manejar un estado que ni siquiera se guarda (se deduce) y escondería la operación de negocio detrás de un dato.
- **Validar en la *Form Request* que la fecha cae en lunes o que el Excel tiene sus hojas.** Duplicaría reglas que ya tienen dueño (el dominio y el servicio), y la importación y el alta manual podrían acabar con reglas distintas.
- **`mimes:xlsx` en lugar de `extensions:xlsx`.** Un `.xlsx` es un ZIP por dentro, y según la versión de libmagic se detecta como `application/zip`.
- **Que la prueba de punta a punta falle siempre sin el servicio.** Haría el gate dependiente de un segundo proceso. **Que se salte siempre** daría un falso verde a quien la pide expresamente. Por eso se salta en la suite y falla con `composer test:e2e`.
- **Una suite aparte en `phpunit.xml`, excluida por defecto.** Nadie la ejecutaría nunca, ni siquiera cuando el servicio está levantado.
- **Responder solo con el id al subir un Excel.** Obligaría a un segundo `GET` para saber si se ha leído bien.

## Conceptos aplicados

| Concepto | Definición breve | Dónde verlo |
|---|---|---|
| Adaptador de entrada (*driving*) | Traduce una petición del exterior a una llamada al caso de uso y su resultado a la respuesta | `app-laravel/src/MealPlanning/Infrastructure/Http/StartPlanImportController.php` |
| Subida de ficheros como frontera | El `UploadedFile` de Laravel se convierte en un DTO de primitivos antes de entrar en el núcleo | `PlanDocumentRequest::toDocument()` |
| Validación de forma frente a reglas | La *Form Request* comprueba tipos y formatos; el dominio y el servicio, el contenido | `PlanDocumentRequest`, `ConfirmPlanImportRequest` |
| Consulta (CQS) y DTO de lectura | Leer sin cambiar nada y devolver primitivos, no el agregado | `app-laravel/src/MealPlanning/Application/GetPlanImport.php`, `PlanImportView.php` |
| Subrecurso con PUT | Un documento de la importación que se sustituye: idempotente | `AttachSelfWeekController`, `ReplaceNutritionistPlanController` |
| Acción como recurso | Una operación de negocio con nombre que crea otro recurso | `ConfirmPlanImportController` |
| API Resource con enlaces | El JSON con sus URL, para que el cliente no componga rutas | `PlanImportResource` |
| `multipart` en PUT | PHP 8.4 y `request_parse_body()`, que usa Symfony | Comentario de `AttachSelfWeekController` |
| Sustituir un puerto en el contenedor | El test pone su adaptador sin tocar el núcleo | `PlanImportEndpointsTest` (`beforeEach`) |
| Pirámide de tests | Muchos tests rápidos con *fakes* y una única prueba de punta a punta | `PlanImportEndpointsTest`, `PlanImportEndToEndTest` |
| Test que se salta según el entorno | `markTestSkipped()` si falta una dependencia externa, y `fail()` cuando se pide expresamente | `PlanImportEndToEndTest` (`beforeEach`), `composer.json` (`test:e2e`) |

## Diagramas

- [Importar un plan por la API](../diagramas/HEXM-0033/importar-un-plan-por-la-api.sequence.json) (`sequence`): de los dos Excel a «¿qué me toca hoy?», en cuatro tramos: el Excel de la nutricionista, el de la semana 2 (con `find()` antes de leer), la confirmación y la consulta del menú, entre el cliente, la API de Laravel, los casos de uso, el puerto `PlanReader`, el servicio importer y PostgreSQL.
- [La prueba de punta a punta](../diagramas/HEXM-0033/prueba-de-punta-a-punta.workflow.json) (`workflow`): quién la lanza (el gate o `composer test:e2e`), la pregunta a `GET /health` y sus tres desenlaces: pasa con el servicio real, se salta en la suite normal y falla cuando se pide expresamente.

Se ven con `~/.agents/workspace-kit/bin/diagrams.sh HEXM-0033`, que genera los HTML en `~/.cache/workspace-kit/diagramas/hexagonal/HEXM-0033/`.
