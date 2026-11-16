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

**Datos de prueba**: el Excel real no se versiona (datos de salud). Los tests construyen un Excel ficticio con la misma estructura (`tests/workbooks.py`), y `uv run poe fixtures` lo guarda en `tests/fixtures/plan-nutricionista-ficticio.xlsx` para probar a mano.

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
│   │   └── errors.py     #     InvalidWorkbookError → 422
│   └── reading/          #   lectura de los Excel, sin nada de FastAPI (ADR 0021)
│       ├── models.py     #     el contrato de salida (modelos de Pydantic)
│       ├── text.py       #     traducción del texto de una celda (funciones puras)
│       ├── nutritionist.py #   lector del Excel de la nutricionista (openpyxl)
│       └── problems.py   #     problemas de forma con su hoja y su celda
└── tests/                # pytest
    ├── workbooks.py      #   Excel ficticio para los tests (y para tests/fixtures)
    └── fixtures/         #   plan-nutricionista-ficticio.xlsx (pruebas manuales)
```

## Dependencias

```bash
uv add <paquete>          # de ejecución (como composer require)
uv add --dev <paquete>    # de desarrollo (como composer require --dev)
uv lock --upgrade         # actualizar versiones (como composer update)
```
