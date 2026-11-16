# importer

Servicio Python (FastAPI) que lee los Excel de los planes nutricionales y los devuelve como JSON para Laravel. Para el núcleo hexagonal es solo un adaptador detrás del puerto `PlanReader` (nivel 7 del [roadmap](../../docs/ROADMAP.md)).

Estado: lee el Excel de la nutricionista (HEXM-0029). El de la semana 2 llega en HEXM-0030.

Las decisiones de herramientas y estructura están en el [ADR 0021](../../docs/adr/0021-servicio-importer-herramientas-y-estructura.md), y las de la lectura del Excel de la nutricionista (contrato, traducción y errores), en el [ADR 0022](../../docs/adr/0022-lectura-del-excel-de-la-nutricionista.md).

## Requisitos

| Herramienta | Versión usada | Comprobar |
|---|---|---|
| [uv](https://docs.astral.sh/uv/) | 0.12 | `uv --version` |
| Python | 3.14 (lo fija `.python-version`; si no está, uv lo descarga) | `uv run python --version` |

No hace falta Docker (ADR 0020) ni instalar nada más: uv crea el entorno virtual e instala las dependencias.

## Puesta en marcha

Todos los comandos, desde esta carpeta (`services/importer`):

```bash
uv sync              # crea .venv/ e instala las dependencias de uv.lock (como composer install)
uv run poe serve     # arranca en http://127.0.0.1:8001 con recarga automática (como php artisan serve)
```

Host y puerto se cambian con variables de entorno:

```bash
IMPORTER_PORT=8011 uv run poe serve
IMPORTER_HOST=0.0.0.0 IMPORTER_PORT=8011 uv run poe serve   # visible desde la red: solo si hace falta
```

Comprobarlo:

```bash
curl -i http://127.0.0.1:8001/health
# HTTP/1.1 200 OK
# {"status":"ok","service":"importer"}
```

## Leer el Excel de la nutricionista

`POST /nutritionist-plans/read` recibe el `.xlsx` en el campo `file` (*multipart*) y devuelve la parte de la nutricionista del contrato de `POST /api/nutrition-plans` de Laravel ([ADR 0014](../../docs/adr/0014-contrato-de-entrada-de-un-plan-con-el-formato-de-los-documentos.md)): `number`, `fixed_meals`, `exchanges`, `exchange_rules` y `nutritionist_week`. No guarda nada.

```bash
curl -s -F file=@tests/fixtures/plan-nutricionista-ficticio.xlsx \
  http://127.0.0.1:8001/nutritionist-plans/read | python3 -m json.tool
```

Excel esperado (cabeceras en la fila 3 y datos desde la 4; la hoja «Receta» se ignora):

| Hoja | Columnas |
|---|---|
| «Semana 1» (título en A1: «Plan nutricional 27 - Semana 1») | Menú · Comida · Cena |
| «Desayunos y snacks» | Momento · Menú / indicaciones · Opciones |
| «Intercambios» | Momento · Categoría · Equivalencias / pauta |

Decisiones de traducción (el detalle y el porqué, en el ADR 0022):

| En el Excel | En el JSON |
|---|---|
| Opciones de una celda separadas por `•` | Una opción por viñeta |
| «… y elegir 2 de las opciones» en las indicaciones | `choose: 2`; la descripción se queda tal cual. Si hay opciones sin «elegir N», 422 |
| «alimento (ración) – alimento (ración)» | Un intercambio por elemento (se parte por un guion entre espacios) |
| «ración de alimento» («1 puñado de frutos secos») | `food: "frutos secos"`, `serving: "1 puñado"` |
| «> 1/2 plato» (sin alimento) | `food` = el nombre de la categoría («Vegetales») |
| Frase al final de la celda («La legumbre cuenta como…») | Una regla de `exchange_rules` |
| «Grasas (solo en una de las dos)» | Categoría `fat` y la regla «Grasas: solo en una de las dos (comida o cena)» |
| Columna «Momento» de los intercambios | No se lleva al contrato; los intercambios y las reglas repetidos se quedan una vez |

Si el Excel no tiene la forma esperada, responde **422** con todos los problemas, cada uno con su hoja y su celda:

```json
{"message": "El Excel no tiene la forma esperada.",
 "errors": [{"sheet": "Semana 1", "cell": null, "message": "Hoja «Semana 1»: falta el menú 4"}]}
```

Una petición sin el campo `file` recibe el 422 estándar de FastAPI (`{"detail": [...]}`): es un error de la petición, no del Excel.

**Datos de prueba**: los Excel reales no se versionan (datos de salud). Los tests construyen Excel ficticios con la misma estructura (`tests/workbooks.py`), y `uv run poe fixtures` los guarda en `tests/fixtures/` (`plan-nutricionista-ficticio.xlsx` y `semana-2-ficticia.xlsx`) para probar a mano.

## Leer el Excel de la semana 2

`POST /self-weeks/read` recibe el Excel de la semana 2 que elabora el usuario (campo `file`, *multipart*) y devuelve la parte `self_week` del mismo contrato: `{"self_week": [...]}`, con los días del 8 al 14 en orden y el 13 vacío (`lunch` y `dinner` a `null`).

```bash
curl -s -F file=@tests/fixtures/semana-2-ficticia.xlsx \
  http://127.0.0.1:8001/self-weeks/read | python3 -m json.tool
```

Excel esperado (las dos hojas, con las cabeceras en la fila 1):

| Hoja | Columnas |
|---|---|
| «Detalle» | Día (8-14) · Tipo (Comida o Cena) · Plato · Ingredientes / cantidades |
| «Resumen» | Una columna por día («Día 8» … «Día 14», de B a H) y las filas «Comida» (2) y «Cena» (3), con el nombre de cada plato |

Decisiones (el detalle y el porqué, en el [ADR 0023](../../docs/adr/0023-lectura-del-excel-de-la-semana-2.md)):

| En el Excel | En el JSON |
|---|---|
| «Detalle» | La fuente: un plato por fila, con su nombre e ingredientes |
| «Resumen» | Se comprueba que diga los mismos platos (sin distinguir mayúsculas ni tildes). Si no coincide, 422 con las dos celdas |
| Ingredientes separados por comas | Un ingrediente por trozo, con su texto entero: `{"name": "⅓ vaso de arroz en crudo", "quantity": null}`. Las comas dentro de paréntesis y la coma decimal no parten |
| Un punto que acaba una frase («…2 huevos. Chocolate 85%.») | También parte; el de una abreviatura («1 cda. de») no |
| Día 13 sin plato (o sin filas) | `{"day": 13, "lunch": null, "dinner": null}` |
| Plato sin ingredientes | `ingredients: []` |

La cantidad no se separa del ingrediente: hacerlo con fiabilidad es trabajo de un LLM (nivel 9, `IngredientExtractor`).

Un Excel mal formado responde **422** con el mismo formato que el de la nutricionista, por ejemplo:

- «Hoja «Detalle», celda A4: la comida del día 8 está repetida»;
- «Hoja «Detalle», celda B2: el tipo «Merienda» no es «Comida» ni «Cena»»;
- «Hoja «Resumen», celda C3: el plato «X» no coincide con el de la hoja «Detalle» (celda C5: «Y»)».

Subir aquí el Excel de la nutricionista da «falta la hoja «Detalle»»: cada formato tiene su ruta y no se adivina.

## Contrato con Laravel

Laravel lee los Excel a través del puerto `PlanReader`, cuyo adaptador `HttpPlanReader` llama a las dos rutas de arriba (HEXM-0031). Los dos lados se prueban por separado, así que comparten un **contrato versionado** en `contracts/importer/`, en la raíz del repositorio ([ADR 0024](../../docs/adr/0024-puerto-planreader-y-contrato-con-el-importador.md)):

| Fichero | Qué es |
|---|---|
| `*.schema.json` | El JSON Schema de cada respuesta (las dos rutas y su 422), generado desde los modelos de Pydantic. |
| `examples/*.json` | Respuestas reales de las rutas con los Excel ficticios de `tests/workbooks.py`. |

```bash
uv run poe contract  # regenera contracts/importer
```

`tests/test_contract.py` falla si un modelo o un lector cambia y el contrato no se ha regenerado. Del lado de Laravel, `ImporterContractTest` valida los ejemplos contra los schemas y los pasa por el adaptador. Cambiar la forma de una respuesta es, por tanto: cambiar el modelo, regenerar el contrato, revisar su diff y adaptar Laravel si sus tests se ponen en rojo.

Además del contrato, hay una **prueba de punta a punta** (HEXM-0033): Laravel importa un plan por su API (`/api/plan-imports`) con este servicio levantado y los Excel de `tests/fixtures/`. Con el servicio en marcha (`uv run poe serve`), desde `app-laravel`: `composer test:e2e`.

## Documentación de la API

FastAPI genera la documentación de la API a partir del código: <http://127.0.0.1:8001/docs> (Swagger UI) y el contrato en <http://127.0.0.1:8001/openapi.json>.

## Gate de calidad

```bash
uv run poe check     # como composer ci:check
```

Ejecuta por orden, parando en el primer fallo:

| Paso | Tarea suelta | Qué comprueba | Equivalente en Laravel |
|---|---|---|---|
| 1. Formato | `uv run poe format:check` | ruff format, sin tocar nada. Para corregirlo: `uv run poe lint`. | Pint `--test` |
| 2. Lint | `uv run poe lint:check` | ruff check: estilo, imports, errores probables. | (Pint + reglas de PHPStan) |
| 3. Tipos | `uv run poe types:check` | mypy en modo estricto (con el plugin de Pydantic). | PHPStan + Larastan `max` |
| 4. Tests | `uv run poe test` | pytest. | Pest |

`uv run poe` sin argumentos lista todas las tareas.

## Estructura

```
services/importer/
├── pyproject.toml        # como composer.json: dependencias, tareas y configuración de herramientas
├── uv.lock               # como composer.lock: versiones exactas (se versiona)
├── .python-version       # versión de Python del entorno virtual
├── .venv/                # como vendor/ (no se versiona)
├── src/importer/         # el paquete (src layout)
│   ├── main.py           #   raíz de composición: create_app() y la app de uvicorn
│   ├── api/              #   capa HTTP: rutas de FastAPI (adaptador de entrada)
│   │   ├── health.py
│   │   ├── nutritionist_plans.py  # POST /nutritionist-plans/read
│   │   ├── self_weeks.py #     POST /self-weeks/read
│   │   └── errors.py     #     InvalidWorkbookError → 422 (para las dos rutas)
│   └── reading/          #   lectura de los Excel, sin nada de FastAPI (ADR 0021)
│       ├── models.py     #     el contrato de salida (modelos de Pydantic)
│       ├── text.py       #     traducción del texto de una celda (funciones puras)
│       ├── workbook.py   #     lo común a los lectores: abrir, leer celdas, reunir problemas
│       ├── nutritionist.py #   lector del Excel de la nutricionista (openpyxl)
│       ├── self_week.py  #     lector del Excel de la semana 2
│       └── problems.py   #     problemas de forma con su hoja y su celda
└── tests/                # pytest
    ├── workbooks.py      #   Excel ficticios para los tests (y para tests/fixtures)
    ├── contract.py       #   genera el contrato con Laravel (contracts/importer, ADR 0024)
    └── fixtures/         #   los mismos Excel ficticios, para las pruebas manuales
```

## Dependencias

```bash
uv add <paquete>          # de ejecución (como composer require)
uv add --dev <paquete>    # de desarrollo (como composer require --dev)
uv lock --upgrade         # actualizar versiones (como composer update)
```
