# 0024. Puerto PlanReader, adaptador HTTP y contrato con el importador

**Fecha:** 2026-11-16 · **Estado:** aceptada · **Tarea:** HEXM-0031 · **Relacionado:** [ADR 0007](0007-adaptador-http-validacion-de-forma-y-errores-de-dominio.md), [ADR 0012](0012-el-reloj-como-puerto-de-aplicacion.md), [ADR 0014](0014-contrato-de-entrada-de-un-plan-con-el-formato-de-los-documentos.md), [ADR 0017](0017-puerto-recipecatalog-y-capa-anticorrupcion.md), [ADR 0022](0022-lectura-del-excel-de-la-nutricionista.md), [ADR 0023](0023-lectura-del-excel-de-la-semana-2.md)

## Contexto

El servicio `services/importer` (Python + FastAPI) ya lee los dos documentos de un plan:

- `POST /nutritionist-plans/read` lee el Excel de la nutricionista (ADR 0022);
- `POST /self-weeks/read` lee el Excel de la semana 2 (ADR 0023).

Cada ruta devuelve su parte del contrato del alta de un plan (ADR 0014). Laravel tiene que consumirlas sin que el núcleo sepa que hay un servicio Python detrás. Quedaban cuatro preguntas abiertas:

1. dónde vive el puerto y qué devuelve;
2. cómo se traducen los errores de la red y del otro servicio;
3. cómo se prueba sin el servicio;
4. cómo se garantiza que Python y PHP siguen hablando el mismo JSON, si se prueban por separado.

## Decisión

### 1. El puerto `PlanReader`, en `MealPlanning\Application`, con una lectura por documento

```php
interface PlanReader
{
    public function readNutritionistPlan(PlanDocument $document): NutritionistPlanReading;
    public function readSelfWeek(PlanDocument $document): SelfWeekReading;
}
```

- **Va en `Application` y no en `Domain`**, como `RecipeCatalog` (ADR 0017) y `Clock` (ADR 0012). Leer un fichero externo es orquestación, y solo lo harán los casos de uso de la importación (HEXM-0032, HEXM-0033). El dominio no hace preguntas al exterior.
- **Dos métodos, uno por formato**, como las dos rutas del servicio: quien sube el fichero sabe cuál es.
- **La entrada es un `PlanDocument`**: el nombre del fichero y su contenido, como textos. El núcleo no conoce `UploadedFile`. Se lleva el contenido y no la ruta para que el adaptador no dependa del disco; los Excel ocupan pocos KB.
- **La salida son DTO de primitivos**, no el agregado. `NutritionistPlanReading` lleva `number`, `fixedMeals`, `exchanges`, `exchangeRules` y `nutritionistWeek`; `SelfWeekReading`, `selfWeek`. Sus arrays tienen la misma forma PHPStan que los parámetros de `RegisterNutritionPlanCommand`, algo más estricta (sin platos nulos). Leer un documento no es crear un plan: falta la otra semana y la fecha de inicio, y un `NutritionPlan` exige las dos semanas. Con esta forma, HEXM-0032 junta las dos lecturas en un comando copiando, sin traducir.

### 2. Errores del puerto: 422 si el documento está mal, 503 si el lector no puede

| Lo que pasa | Error del puerto | HTTP |
|---|---|---|
| El servicio responde 422 con sus problemas: el Excel está mal | `UnreadableDocument` (hereda de `DomainException`) | 422 |
| Sin conexión, conexión rechazada o *timeout* (`ConnectionException`) | `PlanReaderUnavailable` | 503 |
| El servicio responde 5xx u otro código inesperado | `PlanReaderUnavailable` | 503 |
| El servicio responde 200 con un JSON que no cumple el contrato | `PlanReaderUnavailable` | 503 |
| El servicio responde 422 con otra forma (el `{"detail"}` genérico de FastAPI: la petición de Laravel estaba mal) | `PlanReaderUnavailable` | 503 |

- **`UnreadableDocument` es un error del usuario.** El documento lo elabora y lo sube él, y corregirlo lo arregla. Por eso es una `DomainException`, como `UnknownRecipe`, y el manejador global la convierte en 422. Su mensaje enumera todos los problemas en español, con su hoja y su celda, tal como los escribe el servicio. `problems()` los devuelve uno a uno para una futura pantalla.
- **`PlanReaderUnavailable` hereda de una base nueva, `Shared\Application\ServiceUnavailable`**, que el manejador global de `bootstrap/app.php` convierte en **503** para cualquier contexto, igual que `NotFound` → 404. Es 503 y no 500 porque el fallo no es del código propio sino de una dependencia, y suele ser pasajero. No es 502 porque la aplicación no es un proxy: el usuario no sabe que hay otro servicio, y el mensaje tampoco se lo cuenta.
- **Al contrario que los 422 y los 404, sí se registra en el log.** Un servicio caído es un fallo del sistema.
- **El usuario recibe un mensaje genérico en español** («Ahora mismo no se pueden leer los documentos del plan…»). El error técnico original va como `$previous`: la `ConnectionException`, el código y el cuerpo del 5xx, o un `InvalidImporterResponse` con la ruta exacta del campo que falla.
- **Un contrato roto es «no disponible»**: dos versiones desplegadas que no se entienden. El usuario no puede hacer nada para arreglarlo, y quien sí puede lo verá en el log.

### 3. El adaptador `HttpPlanReader` y el fake `InMemoryPlanReader`

- **`MealPlanning\Infrastructure\Importer\HttpPlanReader`** usa el cliente HTTP de Laravel. Recibe la `Factory` inyectada, y no la facade, para que se vea qué necesita la clase. Sube el documento como *multipart* en el campo `file`, que es lo que espera el `UploadFile` de FastAPI.
  - La URL y el *timeout* van en `config/services.php` (`services.importer.url` y `services.importer.timeout`), con `IMPORTER_URL` (por defecto `http://127.0.0.1:8001`, el de `uv run poe serve`) e `IMPORTER_TIMEOUT` (10 s).
  - Los inyecta `MealPlanningServiceProvider` con una *closure*.
  - No se reintenta: la importación la lanza el usuario, que puede repetirla.
- **`ImporterResponse` traduce el JSON y es estricto**: falta un campo, sobra uno o cambia un tipo → `InvalidImporterResponse`. No comprueba las reglas del negocio (las aplica el dominio al dar de alta el plan) ni los mínimos del schema (los garantiza Pydantic, y si no, los para el dominio). No usa una librería de JSON Schema en producción: tiene que recorrer el JSON para construir los DTO de todas formas, y comprobar los tipos por el camino es poco código más.
- **`InMemoryPlanReader`** es el fake de los tests, con tres formas: `returning()`, `rejecting()` y `unavailable()`. Los casos de uso de la importación se probarán con él, sin el servicio.
- **Los dos adaptadores cumplen la misma batería de tests de contrato del puerto** (`PlanReaderContractTest`), como `RecipeCatalog`. El adaptador HTTP se prueba con `Http::fake()` y `Http::preventStrayRequests()`, así que ningún test sale a la red.

### 4. *Contract testing* entre Python y PHP: JSON Schema y ejemplos versionados en `contracts/importer`

El contrato vive en la raíz del repositorio, porque es de los dos lados. Lo genera el servicio con `uv run poe contract` (`services/importer/tests/contract.py`):

| Fichero | Qué es |
|---|---|
| `nutritionist-plan.schema.json`, `self-week.schema.json`, `invalid-workbook.schema.json` | El JSON Schema de cada respuesta, generado desde los modelos de Pydantic con `model_json_schema(mode="serialization")` |
| `examples/nutritionist-plan.json`, `examples/self-week.json`, `examples/invalid-workbook.json` | Respuestas reales de las rutas, pedidas a la aplicación entera con el `TestClient` y los Excel ficticios de `tests/workbooks.py` |

Cada lado se prueba contra esos ficheros:

- **Python** (`tests/test_contract.py`): falla si lo versionado ya no es lo que generan los modelos y los lectores, o si queda un fichero que ya no se genera. Obliga a regenerar el contrato, y el diff del PR enseña cómo cambia. Los modelos llevan `json_schema_serialization_defaults_required=True` para que el schema diga la verdad sobre la respuesta: `quantity` sale siempre, aunque sea `null`.
- **Laravel**:
  - `ImporterContractTest` valida cada ejemplo contra su schema con `opis/json-schema`, una dependencia solo de desarrollo;
  - comprueba que el *mapper* entiende todos los ejemplos;
  - comprueba que el schema solo describe objetos cerrados con todos sus campos obligatorios, que es lo que asume un *mapper* estricto;
  - además, `PlanReaderContractTest` y `HttpPlanReaderTest` usan los ejemplos como respuestas de `Http::fake()`, en vez de JSON escrito a mano.

Se comprobó de punta a punta al hacer la tarea. Al añadir un campo a `NutritionistMenu`, Python falla hasta regenerar el contrato. Con el contrato regenerado, Laravel falla con `«nutritionist_week.0»: sobran los campos weekday`.

## Alternativas descartadas

- **Solo ejemplos compartidos, sin schema.** No añade dependencias, pero el contrato queda implícito: solo cubre lo que aparece en los ejemplos (un campo que en los ficticios siempre es `null` no dice qué tipo tiene).
- **Versionar el `/openapi.json` de FastAPI.** Es más completo (rutas, códigos), pero el documento es grande y ruidoso de leer en un PR, y describe cosas (el 422 genérico, `/health`) que a Laravel no le importan.
- **Pact u otra herramienta de *consumer-driven contracts*.** Es la solución industrial, pero necesita un *broker* o ficheros de *pacts* y librerías en los dos lenguajes. Para dos servicios del mismo repositorio, dos ficheros por respuesta dan la misma garantía con mucho menos.
- **Probar el adaptador contra el servicio de verdad** (levantar uvicorn en los tests de Laravel). Son tests lentos y frágiles, y acoplan los dos gates; el contrato da la misma confianza sin levantar nada.
- **Que el puerto devuelva el agregado o un `RegisterNutritionPlanCommand`.** Un documento solo no es un plan completo, y el comando necesita la fecha de inicio, que no está en ningún Excel.
- **Un único método `read(PlanDocument, DocumentType)`.** Obligaría a devolver un tipo común o un `mixed` y a hacer un `match` en cada adaptador. Dos métodos dan dos firmas con tipos exactos.
- **Dejar escapar las excepciones del cliente HTTP** y traducirlas en el caso de uso. El caso de uso conocería HTTP, y el día que el lector fuera otro (el PDF, nivel 15) habría que cambiarlo.
- **Validar en producción contra el JSON Schema** (librería en `require`). Duplica la comprobación que el *mapper* ya hace al construir los DTO.

## Consecuencias

- **A favor:**
  - El núcleo puede leer los dos documentos de un plan sin saber que hay un servicio Python, HTTP o JSON. Cambiar el lector (el PDF con un LLM en el nivel 15, o reescribirlo en PHP) solo cambia el adaptador.
  - Los casos de uso de la importación (HEXM-0032, HEXM-0033) se pueden probar con el fake, y el contrato garantiza que el fake no miente.
  - Un cambio del JSON del servicio no puede llegar a producción sin que se ponga en rojo algún test: primero el de Python y, con el contrato regenerado, el de Laravel.
  - El 503 queda resuelto para cualquier servicio externo futuro (`IngredientExtractor`, `RecipeFinder`): basta con heredar de `ServiceUnavailable`.
- **En contra:**
  - Hay un paso más al cambiar un modelo de Pydantic: regenerar el contrato con `uv run poe contract` y versionarlo.
  - Los ejemplos salen de los Excel ficticios: lo que esos ficheros no contengan no aparece en ellos. El schema cubre la forma del resto.
  - Una dependencia de desarrollo más en Laravel (`opis/json-schema`).
- **Revisar cuando:**
  - el servicio se despliegue por separado y pueda ir por delante o por detrás de Laravel: entonces habrá que pensar en versionar el contrato (rutas `/v1`, compatibilidad hacia atrás);
  - haya CI: los dos gates ya ejecutan sus tests de contrato, pero conviene que corran en el mismo *pipeline*;
  - aparezca un segundo consumidor del servicio.
