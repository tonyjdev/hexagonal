# Explicación HEXM-0028: Esqueleto del servicio importer en FastAPI

## Explicación

El nivel 7 abre el bloque B: el primer código Python del proyecto. Antes de leer ningún Excel había que montar el **terreno**: cómo se describe un proyecto Python, dónde viven sus dependencias, cómo se comprueba su calidad con la misma exigencia que el PHP, cómo se arranca en local (sin Docker, ADR 0020) y cómo se organiza por dentro.

La idea de la solución: copiar, pieza a pieza, lo que ya funciona en `app-laravel`, con su equivalente de Python, para que cada concepto nuevo tenga un ancla conocida:

- `pyproject.toml` gestionado con **uv** hace de `composer.json`, `uv.lock` de `composer.lock` y `.venv/` de `vendor/`;
- **poethepoet** hace de los `scripts` de Composer, con los mismos nombres: `uv run poe check` es `composer ci:check` y `uv run poe serve` es `php artisan serve`;
- el gate tiene las mismas tres patas: formato (ruff ↔ Pint), análisis estático al máximo (mypy estricto ↔ PHPStan `max`) y tests (pytest ↔ Pest);
- un único endpoint, `GET /health`, basta para recorrer todo el camino: servidor, aplicación, ruta, modelo de respuesta y test.

Las decisiones y sus alternativas están en el [ADR 0021](../adr/0021-servicio-importer-herramientas-y-estructura.md).

## Lógica de negocio

Esta tarea no implementa todavía ninguna regla de negocio: el servicio solo dice «estoy vivo». Pero prepara el terreno de una decisión de negocio tomada al planificar el nivel, que se refleja ahora en el roadmap:

- **Un plan se importa desde dos documentos.** La nutricionista entrega el plan 27 en PDF, que el usuario pasa a Excel (número, pautas fijas, intercambios y semana 1, menús 1-6); la semana 2 la elabora el propio usuario en otro Excel (días 8-14). El importador leerá **los dos** (HEXM-0029 y HEXM-0030).
- **Un plan necesita las dos semanas** (regla 1). Como los dos Excel llegan en momentos distintos, lo que se ha leído mientras falta el segundo es una **importación pendiente** (HEXM-0032), que solo se convierte en un `NutritionPlan` cuando tiene los dos documentos y la fecha de inicio. El dominio no se relaja: un plan a medias no es un plan.
- **El nivel 8 cambia de foco.** Como la semana 2 ya se importa de su Excel en el nivel 7, el nivel 8 pasa a crearla y editarla desde la aplicación y, sobre todo, a **hacer cumplir las reglas de los intercambios** («la grasa solo en la comida o en la cena, no en ambas»), que hoy solo se guardan como texto.

## Decisiones de arquitectura

### Dónde encaja el servicio en la hexagonal

```
                 ┌──────────── LARAVEL (núcleo hexagonal) ────────────┐
                 │  MealPlanning                                      │
                 │    RegisterNutritionPlan, importación pendiente    │
                 │    Puerto PlanReader ─────────────┐   (HEXM-0031) │
                 └────────────────────────────────────┼───────────────┘
                                                      │ HTTP (multipart → JSON)
                                     ┌────────────────▼───────────────┐
                                     │ services/importer (FastAPI)     │
                                     │   api/      ← HTTP (hoy: /health)│
                                     │   lectura   ← Excel → modelos    │
                                     │               (HEXM-0029/0030)   │
                                     └──────────────────────────────────┘
```

Para el núcleo, el servicio es **un adaptador más detrás de un puerto**, igual que PostgreSQL detrás de `NutritionPlanRepository`. El dominio de Laravel nunca sabrá que existe Python.

### ¿Hexagonal también por dentro? No del todo

El servicio entero ya es un adaptador, y su trabajo es **traducir** un formato (Excel) a otro (JSON): no tiene reglas de negocio propias que proteger. Montar dentro dominio, aplicación, puertos y adaptadores sería ceremonia sin contenido. Lo que **sí** se mantiene es la separación que importa:

- `src/importer/api/` es la capa HTTP: el **adaptador de entrada** (como los controladores de `app-laravel/src/*/Infrastructure/Http`). Traduce peticiones a llamadas y resultados a JSON; no decide nada.
- La **lectura de los Excel** (HEXM-0029) vivirá en su propio paquete y **no importará nada de FastAPI**: se podrá probar sin HTTP y la capa web se podrá cambiar sin tocarla.

Si el servicio llega a tener lógica propia (el candidato es el nivel 9, con el LLM que separa ingredientes), se revisará.

### Las piezas y su papel

- **`main.py`, raíz de composición.** `create_app()` construye la aplicación y registra los routers, como los *service providers* de Laravel. Es una *application factory*: cada test pide una aplicación nueva, sin estado compartido. Al final del módulo, `app = create_app()` es lo que arranca uvicorn.
- **`api/health.py`, la ruta.** Un `APIRouter` agrupa rutas (como un fichero de `routes/`), y el decorador `@router.get("/health")` registra la función justo encima de ella (como `Route::get()`). Su tipo de retorno, `HealthResponse`, es el **modelo de la respuesta**: FastAPI lo valida, lo serializa y lo documenta en `/openapi.json`, como un *API Resource* que además genera la documentación.
- **`HealthResponse`, modelo de Pydantic.** Declara la forma del JSON con *type hints*; `frozen=True` lo hace inmutable (como un `readonly class`), y `Literal["ok"]` solo admite ese valor.

### El recorrido de una petición

1. `uv run poe serve` lanza `uvicorn importer.main:app` en `127.0.0.1:8001` (el 8000 es de `php artisan serve`).
2. `curl` hace `GET /health`; uvicorn recibe la conexión TCP y llama a la aplicación por **ASGI**, el contrato entre servidor y aplicación (lo que en PHP hacen PHP-FPM y `public/index.php`).
3. FastAPI busca la ruta y llama a `health()`, que crea un `HealthResponse`.
4. FastAPI lo serializa a JSON y uvicorn responde `200 {"status": "ok", "service": "importer"}`.

En los tests, `TestClient(create_app())` se salta el paso 1 y 2: llama a la aplicación **en el mismo proceso**, sin servidor ni puerto, como `$this->getJson()` en Laravel.

### El gate, en el gate del proyecto

`uv run poe check` ejecuta en orden, parando en el primer fallo: `ruff format --check`, `ruff check`, `mypy` (estricto y con el plugin de Pydantic) y `pytest`. `CHECK_CMD` en `CLAUDE.md` ejecuta ahora los dos gates, el de Laravel y el del servicio: una tarea no se cierra si falla cualquiera de los dos.

## Alternativas descartadas

- **`venv` + `pip`** (con `requirements.txt`): viene con Python, pero sin un fichero de bloqueo con las dependencias de desarrollo separadas ni control de la versión de Python. uv hace todo eso y sigue creando un entorno virtual normal en `.venv/`, que se puede activar a mano para ver qué es.
- **Poetry o PDM**: resuelven lo mismo, pero uv es más rápido, sigue los estándares (PEP 621, PEP 735) y es hoy la opción más extendida en proyectos nuevos.
- **Un `Makefile` o un script de shell** para las tareas: dejarían las tareas fuera de `pyproject.toml`; poe las mantiene junto a la configuración, como los `scripts` de `composer.json`.
- **black + isort + flake8**: tres herramientas para lo que ruff hace solo.
- **pyright** en lugar de mypy: mypy es la referencia, tiene plugin oficial de Pydantic y su modo estricto es el paralelo de PHPStan `max`.
- **`fastapi dev`**: esconde el servidor ASGI; con uvicorn se ve qué arranca.
- **Hexagonal completa dentro del servicio**: descartada por ahora, ver arriba.
- **En un Laravel clásico** este servicio no existiría: se leería el Excel con una librería PHP (como PhpSpreadsheet) dentro de la aplicación. Aquí el objetivo del bloque B es justo aprender Python y ver que, para el núcleo, da igual en qué lenguaje esté el adaptador.

## Conceptos aplicados

- **`pyproject.toml` y uv**: el `composer.json` de Python y su gestor. Ver `services/importer/pyproject.toml` (cabecera con la tabla de equivalencias).
- **Entorno virtual**: las dependencias del proyecto aisladas en `.venv/`, como `vendor/`. Ver `services/importer/.gitignore` y el README del servicio.
- **src layout**: el código en `src/importer/` para que los tests usen el paquete instalado. Ver la sección `[build-system]` de `pyproject.toml`.
- **Task runner (poethepoet)**: las tareas en `[tool.poe.tasks]`. Ver `pyproject.toml`.
- **Paquete de Python** (`__init__.py`): el equivalente a un *namespace*. Ver `src/importer/__init__.py`.
- **ASGI y uvicorn**: el contrato servidor ↔ aplicación. Ver `src/importer/main.py`.
- **Application factory**: `create_app()`. Ver `src/importer/main.py`.
- **APIRouter y decoradores**: rutas declaradas junto a su función. Ver `src/importer/api/health.py`.
- **Modelo de Pydantic y `Literal`**: la forma de la respuesta, validada y documentada. Ver `HealthResponse` en `src/importer/api/health.py`.
- **Type hints y mypy estricto**: comprobación estática como PHPStan. Ver `[tool.mypy]` en `pyproject.toml`.
- **pytest y TestClient**: tests como funciones con `assert`, sin servidor. Ver `services/importer/tests/test_health.py`.

## Diagramas

- [El servicio importer: sus tareas, su gate y sus piezas](../diagramas/HEXM-0028/servicio-importer-tareas-y-gate.architecture.json) (`architecture`): ¿qué ejecuta cada tarea y cómo se conectan las piezas? `CHECK_CMD` lanza los dos gates, `poe check` sus tres herramientas, `poe serve` uvicorn sobre `main.py`, y los tests entran por `create_app()`.
- [GET /health de punta a punta](../diagramas/HEXM-0028/get-health-de-punta-a-punta.sequence.json) (`sequence`): ¿qué pasa con una petición? La misma ruta por uvicorn (TCP → ASGI → ruta → modelo → JSON) y por `TestClient`, en el mismo proceso.

Cómo verlos: `cd app-laravel && composer docs:diagrams` y abrir `docs/diagramas/HEXM-0028/<slug>.html`.
