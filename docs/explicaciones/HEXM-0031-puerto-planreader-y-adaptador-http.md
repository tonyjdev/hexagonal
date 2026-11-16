# Explicación HEXM-0031: Puerto PlanReader y adaptador HTTP

## Explicación

El servicio `importer` (Python + FastAPI) ya sabe leer los dos documentos de un plan:

- el Excel de la nutricionista, con `POST /nutritionist-plans/read` (HEXM-0029);
- el Excel de la semana 2, con `POST /self-weeks/read` (HEXM-0030).

Cada uno se devuelve con su parte del contrato del alta de un plan (ADR 0014). Faltaba la otra mitad: que **Laravel** pudiera pedir esas lecturas sin que su núcleo supiera que detrás hay un servicio Python, HTTP o JSON.

Esta tarea añade cuatro piezas:

- el **puerto `PlanReader`**;
- su **adaptador HTTP**, que traduce los fallos de la red y del otro servicio a errores propios;
- un **fake** para probar sin el servicio;
- un **contrato versionado entre Python y PHP** que se pone en rojo si los dos lados dejan de hablar el mismo JSON.

Crear el plan y la importación pendiente quedan para HEXM-0032 y HEXM-0033.

## Lógica de negocio

**Leer no es crear un plan.** Un plan necesita dos semanas (regla 1) y una fecha de inicio. El Excel de la nutricionista trae el número, las pautas fijas, los intercambios y la semana 1. El de la semana 2 trae los días 8 a 14. La fecha no está en ninguno: la dará el usuario al confirmar. Por eso el puerto no devuelve un `NutritionPlan`, sino una **lectura** de cada documento. Las reglas del plan (seis días por semana, sábado libre, el plan empieza en lunes…) las aplicará el dominio al darlo de alta, como con cualquier otro alta.

**Dos clases de fallo, dos responsables.** Al leer un documento pueden pasar dos cosas muy distintas:

| Qué ha pasado | Ejemplo real | Quién lo arregla | Error del puerto | HTTP |
|---|---|---|---|---|
| El documento no tiene la forma esperada | Se sube el Excel de la nutricionista como semana 2: «Hoja «Detalle»: falta la hoja · Hoja «Resumen»: falta la hoja» | El usuario, corrigiendo el Excel | `UnreadableDocument` | 422 |
| El lector no puede leer | El servicio está parado, tarda más de 10 s, falla por dentro (500) o responde un JSON que no cumple el contrato | Quien mantiene el sistema; el usuario solo puede reintentar | `PlanReaderUnavailable` | 503 |

El primero lleva **todos** los problemas en español, cada uno con su hoja y su celda, para corregirlo todo en una sola vuelta. El segundo da al usuario un mensaje genérico («Ahora mismo no se pueden leer los documentos del plan. Inténtalo de nuevo en unos minutos.») y deja el detalle técnico en el log.

## Decisiones de arquitectura

### Por qué cada pieza en su capa

| Pieza | Capa | Por qué ahí |
|---|---|---|
| `PlanReader` (puerto) | `MealPlanning\Application` | Como `RecipeCatalog` y `Clock`: preguntar a un sistema externo es orquestación, y solo lo harán los casos de uso de importación. El dominio no hace preguntas al exterior. |
| `PlanDocument`, `NutritionistPlanReading`, `SelfWeekReading` | `MealPlanning\Application` | Son la entrada y la salida del puerto: DTO con tipos de PHP, sin `UploadedFile` ni arrays de Laravel. Las lecturas tienen la misma forma PHPStan que `RegisterNutritionPlanCommand`, para juntarlas en un comando copiando. |
| `UnreadableDocument` | `MealPlanning\Application` | Acompaña al puerto (como `UnknownRecipe` a `RecipeCatalog`). Hereda de `Shared\Domain\DomainException`, así que el manejador global ya lo convierte en 422. |
| `PlanReaderUnavailable` | `MealPlanning\Application` | El error del puerto para «no se puede leer ahora». Hereda de la base nueva `ServiceUnavailable`. |
| `ServiceUnavailable` | `Shared\Application` | Base de «un sistema externo no responde» para cualquier contexto, como `NotFound`. `bootstrap/app.php` la convierte en 503 una sola vez. |
| `HttpPlanReader`, `ImporterResponse`, `InvalidImporterResponse` | `MealPlanning\Infrastructure\Importer` | El adaptador sí conoce HTTP, JSON y el servicio: es lo único que cambiaría con otro lector. |
| `InMemoryPlanReader` | `MealPlanning\Infrastructure` | Otro adaptador del puerto, como los repositorios en memoria. |
| Binding y configuración | `MealPlanningServiceProvider`, `config/services.php` | La raíz de composición decide qué adaptador hay detrás del puerto y con qué URL y *timeout*. |

### El sentido de las dependencias

```
MealPlanning\Application            MealPlanning\Infrastructure           services/importer (Python)
  PlanReader  ◄──────implementa────── HttpPlanReader ──POST multipart──►  /nutritionist-plans/read
  PlanDocument, *Reading                 │ JSON                           /self-weeks/read
  UnreadableDocument                     ▼                                       │ genera
  PlanReaderUnavailable ──hereda──►   ImporterResponse ◄── ejemplos ──── contracts/importer
        (Shared\Application\ServiceUnavailable → 503)
```

Las flechas de código apuntan hacia dentro. La infraestructura conoce el puerto y sus DTO; el núcleo no conoce `HttpPlanReader`, ni el cliente HTTP, ni que el lector es Python. Los tests de arquitectura lo siguen comprobando: `Illuminate\Http` solo se puede usar en la infraestructura.

### El recorrido de una lectura

1. Un caso de uso (HEXM-0032) recibe un `PlanDocument` y llama a `readSelfWeek()` sobre el `PlanReader` que le inyecta el contenedor.
2. `HttpPlanReader::upload()` sube el contenido con `attach('file', …)` (*multipart*) a `IMPORTER_URL/self-weeks/read`, con `acceptJson()` y el *timeout* configurado.
3. Según lo que pase:
   - si la conexión falla o no hay respuesta a tiempo, salta `ConnectionException` → `PlanReaderUnavailable::unreachable()`;
   - un **422** se pasa por `ImporterResponse::problems()`. Si trae la forma acordada (`{message, errors: [{sheet, cell, message}]}`), da `UnreadableDocument` con los problemas. Si trae otra (el `{"detail": …}` genérico de FastAPI significa que la petición de Laravel estaba mal), es un contrato roto → `PlanReaderUnavailable`;
   - un **5xx u otro código** da `PlanReaderUnavailable::unexpectedResponse()`, con el código y el principio del cuerpo para el log;
   - un **2xx** se pasa por `ImporterResponse::selfWeek()`, que recorre el JSON campo a campo con las claves exactas. Si falta, sobra o cambia algo, `InvalidImporterResponse` («`self_week.3.lunch`: faltan los campos name») se envuelve en `PlanReaderUnavailable`. Si todo cuadra, devuelve la `SelfWeekReading`.
4. Si el caso de uso deja escapar el error del puerto, el manejador global responde 422 o 503 con `{"message": "…"}`.

### El contrato entre Python y PHP

Los dos lados se prueban por separado y en lenguajes distintos. ¿Cómo se sabe que siguen hablando el mismo JSON? Con un contrato versionado en `contracts/importer/`, que genera el servicio (`uv run poe contract`):

- **El JSON Schema** de cada respuesta (las dos rutas y su 422), sacado de los modelos de Pydantic con `model_json_schema(mode="serialization")`. Para que el schema diga la verdad sobre la respuesta, los modelos llevan `json_schema_serialization_defaults_required=True`: `quantity` sale siempre, aunque sea `null`, y por tanto es obligatorio.
- **Ejemplos reales**: lo que responde la aplicación entera con el `TestClient` y los Excel ficticios.

Cada lado comprueba su parte:

| Lado | Test | Falla si… |
|---|---|---|
| Python | `tests/test_contract.py` | lo versionado ya no es lo que generan los modelos y los lectores, o sobra un fichero |
| Laravel | `ImporterContractTest` | un ejemplo no cumple su schema (validado con `opis/json-schema`, solo en desarrollo), el *mapper* no entiende un ejemplo, o el schema admite campos opcionales o de más |
| Laravel | `PlanReaderContractTest`, `HttpPlanReaderTest` | el adaptador no lee los ejemplos campo a campo igual (se usan como respuestas de `Http::fake()`) |

Se comprobó de punta a punta. Al añadir un campo `weekday` a `NutritionistMenu`, Python falló hasta regenerar el contrato. Con el contrato regenerado, Laravel falló con «`nutritionist_week.0`: sobran los campos weekday».

## Alternativas descartadas

- **Cómo se haría en un Laravel clásico:** un controlador que llama a `Http::post()` directamente y lee `$response['self_week']`. Funciona, pero el caso de uso quedaría atado a HTTP y al JSON del servicio. Para probarlo habría que simular HTTP en cada test, y el día que el lector fuera un PDF con un LLM (nivel 15) habría que reescribir la lógica de importación. Con el puerto, todo eso es un adaptador.
- **Que el puerto devuelva el agregado o un `RegisterNutritionPlanCommand`:** un documento solo no es un plan, y el comando necesita la fecha de inicio, que no está en ningún Excel.
- **Un método `read(documento, tipo)`:** obligaría a devolver un tipo común o `mixed`. Dos métodos dan firmas con tipos exactos, como las dos rutas del servicio.
- **Dejar escapar `ConnectionException` y traducirla en el caso de uso:** el núcleo conocería HTTP.
- **Un *mapper* permisivo**, que ignorase los campos que no conoce: dejaría pasar en silencio un cambio del contrato, justo lo que se quiere detectar.
- **Solo ejemplos compartidos, sin schema:** el contrato quedaría implícito y solo cubriría lo que aparece en los ejemplos.
- **Versionar el `/openapi.json` de FastAPI:** es más completo, pero grande, ruidoso en un PR y con cosas que a Laravel no le importan.
- **Pact:** es la solución industrial, pero necesita *broker* y librerías en los dos lenguajes. Para dos servicios del mismo repositorio, dos ficheros por respuesta dan la misma garantía.
- **Probar el adaptador contra uvicorn levantado en los tests de Laravel:** serían tests lentos y frágiles, y acoplarían los dos gates.
- **Validar en producción contra el schema:** duplicaría lo que el *mapper* ya comprueba al construir los DTO.
- **Responder 500 o 502 cuando el servicio cae:** 500 dice «fallo nuestro» y no invita a reintentar. 502 es de un proxy y le contaría al usuario que hay otro servicio. Por eso 503.

## Conceptos aplicados

| Concepto | Definición breve | Dónde verlo |
|---|---|---|
| Servicio externo como adaptador detrás de un puerto | El núcleo define la pregunta en su idioma y un adaptador la responde hablando con el sistema de fuera | `app-laravel/src/MealPlanning/Application/PlanReader.php`, `app-laravel/src/MealPlanning/Infrastructure/Importer/HttpPlanReader.php` |
| DTO de salida de un puerto | Datos primitivos con la forma que necesita el siguiente paso, no objetos del dominio | `NutritionistPlanReading.php`, `SelfWeekReading.php` |
| Traducción de errores | Los errores técnicos (red, códigos HTTP, JSON) se convierten en errores del puerto, y el original viaja como `previous` | `HttpPlanReader::upload()`, `PlanReaderUnavailable.php` |
| Excepción base por tipo de respuesta | Una base compartida por cada «clase» de error que un manejador global traduce una vez (422, 404, 503) | `app-laravel/src/Shared/Application/ServiceUnavailable.php`, `app-laravel/bootstrap/app.php` |
| *Mapper* estricto en el borde | Lo que llega por la red es `mixed`: se comprueba campo a campo antes de construir tipos de verdad | `app-laravel/src/MealPlanning/Infrastructure/Importer/ImporterResponse.php` |
| Genéricos de PHPStan | `@template` en un docblock para que `map()` conserve el tipo de cada lista | `ImporterResponse::map()` |
| Fake de un puerto | Una implementación en memoria que cumple los mismos tests de contrato que la real | `app-laravel/src/MealPlanning/Infrastructure/InMemoryPlanReader.php`, `app-laravel/tests/Feature/MealPlanning/Infrastructure/PlanReaderContractTest.php` |
| `Http::fake()` y `preventStrayRequests()` | Sustituir las respuestas del cliente HTTP de Laravel y garantizar que ningún test sale a la red | `app-laravel/tests/Feature/MealPlanning/Infrastructure/Importer/HttpPlanReaderTest.php` |
| *Contract testing* entre dos lenguajes | Cada lado se prueba contra un documento compartido (schema + ejemplos), sin levantar el otro | `contracts/importer/`, `services/importer/tests/contract.py`, `app-laravel/tests/Feature/MealPlanning/Infrastructure/Importer/ImporterContractTest.php` |
| JSON Schema generado por Pydantic | `model_json_schema(mode="serialization")` describe la respuesta; `extra="forbid"` → `additionalProperties: false` | `services/importer/src/importer/reading/models.py` (`ContractModel`) |
| *Snapshot test* | Comparar lo generado con una «foto» versionada; el diff del PR muestra el cambio | `services/importer/tests/test_contract.py` |
| Configuración por entorno | La dirección del servicio es configuración que inyecta la raíz de composición | `app-laravel/config/services.php`, `MealPlanningServiceProvider.php` |

## Diagramas

- [El importador detrás de un puerto](../diagramas/HEXM-0031/importador-detras-de-un-puerto.architecture.json) (`architecture`): el puerto y sus dos adaptadores, los errores del puerto con la base `ServiceUnavailable`, el servicio Python y el contrato que generan unos y comprueban otros.
- [Traducir la respuesta del importador](../diagramas/HEXM-0031/traducir-la-respuesta-del-importador.workflow.json) (`workflow`): qué sale de cada respuesta (lectura, `UnreadableDocument` o `PlanReaderUnavailable`) y por qué rama.

Se ven con `~/.agents/workspace-kit/bin/diagrams.sh HEXM-0031`, que genera los HTML en `~/.cache/workspace-kit/diagramas/hexagonal/HEXM-0031/`.
