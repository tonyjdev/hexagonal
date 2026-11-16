# 0021. Servicio importer: herramientas de Python y estructura

**Fecha:** 2026-11-16 · **Estado:** aceptada · **Tarea:** HEXM-0028

## Contexto

El nivel 7 abre el bloque B con el primer servicio Python, `services/importer`: leerá los Excel de los planes (el de la nutricionista y el de la semana 2 del usuario) y los devolverá como JSON. Para Laravel será un adaptador detrás del puerto `PlanReader` (HEXM-0031).

Antes de escribir lógica hay que decidir con qué se gestiona el proyecto Python, cuál es su gate de calidad, cómo se arranca en local (sin Docker, [ADR 0020](0020-postgresql-nativo-en-desarrollo-local.md)) y cómo se organiza por dentro. El proyecto es de aprendizaje, así que cada elección debe poder compararse con su equivalente en PHP/Laravel.

## Decisión

### 1. uv para el proyecto y el entorno virtual

El proyecto se describe en `pyproject.toml` (PEP 621) y se gestiona con **[uv](https://docs.astral.sh/uv/)**:

| PHP / Composer | Python / uv |
|---|---|
| `composer.json` | `pyproject.toml` |
| `composer.lock` (se versiona) | `uv.lock` (se versiona) |
| `vendor/` (no se versiona) | `.venv/`, el entorno virtual (no se versiona) |
| `composer install` / `require` / `require --dev` / `update` | `uv sync` / `uv add` / `uv add --dev` / `uv lock --upgrade` |
| `"php": "^8.5"` | `requires-python = ">=3.14"` + `.python-version` |

Las dependencias de desarrollo van en un grupo de dependencias (`[dependency-groups] dev`, PEP 735), el equivalente a `require-dev`.

### 2. poethepoet para las tareas

uv no tiene un equivalente a los `scripts` de `composer.json`. Las tareas se declaran en `[tool.poe.tasks]` del propio `pyproject.toml` y se ejecutan con **poethepoet**: `uv run poe <tarea>`. Los nombres copian los de `app-laravel/composer.json` (`lint`, `format:check`, `types:check`, `test`) y el gate se llama `check`.

### 3. Gate del servicio: ruff, mypy estricto y pytest

`uv run poe check` ejecuta, de lo más rápido a lo más lento y parando en el primer fallo (como `composer ci:check`):

1. `ruff format --check`: formato (como Pint).
2. `ruff check`: estilo, orden de los import y errores probables.
3. `mypy` con `strict = true` y el plugin de Pydantic: todo con *type hints*, sin `Any` implícito (como PHPStan al nivel `max`).
4. `pytest`: los endpoints se prueban con el `TestClient` de FastAPI, sin levantar servidor.

El gate del proyecto (`CHECK_CMD` en `CLAUDE.md`) pasa a ejecutar el de Laravel y, después, el del servicio.

### 4. Arranque en local con uvicorn

`uv run poe serve` lanza `uvicorn importer.main:app --reload`, por defecto en `127.0.0.1:8001` (el 8000 es de `php artisan serve`), con `IMPORTER_HOST` e `IMPORTER_PORT` para cambiarlos. Es el equivalente a `php artisan serve`. El `Dockerfile` del servicio llegará con el primer despliegue.

### 5. Estructura: *src layout* y HTTP separado de la lectura, sin hexagonal completa

```
src/importer/
├── main.py      # raíz de composición: create_app() (application factory)
└── api/         # adaptador de entrada: rutas de FastAPI
```

- **src layout** (`src/importer/` y no `importer/` en la raíz): los tests importan el paquete instalado en el entorno virtual, no la carpeta por estar en el directorio actual. Un error de empaquetado sale en los tests, no en producción. Es la idea del `autoload` de Composer: el código se encuentra porque está declarado.
- **`create_app()`** en vez de crear la aplicación al importar: cada test recibe una aplicación limpia, y más adelante se le podrá pasar configuración o dependencias.
- **¿Hexagonal también por dentro?** No por completo. El servicio entero ya es un adaptador del núcleo de Laravel; su trabajo es traducir un formato (Excel) a otro (JSON), sin reglas de negocio propias. Montar dominio, aplicación, puertos y adaptadores dentro de un traductor sería complejidad sin nada que proteger. **Sí se mantiene una regla:** la lectura de los Excel (HEXM-0029, HEXM-0030) vivirá en su propio paquete, sin importar nada de FastAPI, y `api/` solo traducirá HTTP ↔ lectura. Así la lectura se prueba sin HTTP y la capa web se puede cambiar sin tocarla. Si el servicio llegara a tener lógica propia (el nivel 9 con el LLM es el candidato), se revisaría esta decisión.

### Alternativas descartadas

- **`venv` + `pip`** (con `requirements.txt` o `pip-tools`): es lo que viene con Python, pero no tiene un fichero de bloqueo nativo con las dependencias de desarrollo separadas ni gestiona la versión de Python; habría que combinar varias herramientas para lo que uv hace solo. Para aprender el concepto de entorno virtual, uv lo crea igualmente en `.venv/` y se puede activar a mano (`source .venv/bin/activate`).
- **Poetry o PDM**: resuelven lo mismo, pero uv es más rápido, sigue los estándares (PEP 621 y PEP 735) sin secciones propias y es hoy la opción más extendida en proyectos nuevos.
- **`Makefile` o un script de shell** para las tareas: funcionan, pero dejan las tareas fuera de `pyproject.toml`; poe las mantiene junto a la configuración, igual que los `scripts` de `composer.json`.
- **black + isort + flake8** en lugar de ruff: tres herramientas para lo que ruff hace solo y mucho más rápido.
- **pyright** en lugar de mypy: muy bueno, pero mypy es la referencia (y el nombre que pedía la tarea), tiene plugin oficial de Pydantic y su modo estricto es el paralelo directo de PHPStan.
- **`fastapi dev`** (la CLI de FastAPI) en lugar de uvicorn directo: esconde el servidor ASGI; con uvicorn se ve qué arranca y por qué.

## Consecuencias

- **A favor:** un solo comando para preparar el entorno (`uv sync`) y otro para el gate; versiones reproducibles con `uv.lock`; la misma disciplina que en PHP (formato, análisis estático al máximo y tests) desde el primer fichero.
- **En contra:** hay que instalar uv (una vez) y aprender poe, una herramienta más. El gate del proyecto tarda unos segundos más.
- **Para los siguientes pasos:** HEXM-0029 añadirá el paquete de lectura (con `openpyxl`) y los modelos Pydantic del contrato; el contrato OpenAPI que FastAPI genera desde los *type hints* es la base de los tests de contrato con Laravel (HEXM-0031).
