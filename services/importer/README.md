# importer

Servicio Python (FastAPI) que lee los Excel de los planes nutricionales y los devuelve como JSON para Laravel. Para el núcleo hexagonal es solo un adaptador detrás del puerto `PlanReader` (nivel 7 del [roadmap](../../docs/ROADMAP.md)).

Estado: esqueleto (HEXM-0028). Solo responde `GET /health`; leer el Excel de la nutricionista llega en HEXM-0029 y el de la semana 2 en HEXM-0030.

Las decisiones de herramientas y estructura están en el [ADR 0021](../../docs/adr/0021-servicio-importer-herramientas-y-estructura.md).

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
│   └── api/              #   capa HTTP: rutas de FastAPI (adaptador de entrada)
│       └── health.py
└── tests/                # pytest
```

## Dependencias

```bash
uv add <paquete>          # de ejecución (como composer require)
uv add --dev <paquete>    # de desarrollo (como composer require --dev)
uv lock --upgrade         # actualizar versiones (como composer update)
```
