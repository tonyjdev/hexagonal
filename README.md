# Hexagonal: planes nutricionales y recetario

Proyecto de aprendizaje de **arquitectura hexagonal** con PHP/Laravel, **Python/FastAPI** e **IA aplicada**, construido sobre un caso real: organizar los planes de la nutricionista (menús, pautas, intercambios y calendario) y un recetario asociado.

## Documentación

- [Convenciones](docs/CONVENCIONES.md): idioma, nomenclatura, comentarios y forma de trabajar.
- [Roadmap](docs/ROADMAP.md): niveles del proyecto y estado actual.
- [Dominio](docs/DOMINIO.md): glosario (lenguaje ubicuo) y reglas de negocio.
- [Tareas](docs/TAREAS.md): gestión de tareas con Workspace (proyecto `HEXM`).
- [Explicaciones](docs/explicaciones/): por qué se hizo cada tarea así, a nivel de arquitectura y de negocio.
- [Diario](docs/diario/): qué se aprendió en cada nivel.
- [ADR](docs/adr/): decisiones de arquitectura.

## Stack

- PHP 8.5 · Laravel 13 · PostgreSQL 18 (+ pgvector)
- Python · FastAPI (servicios satélite)
- LLM / embeddings / RAG / agentes (bloque C del roadmap)

## Puesta en marcha

### Requisitos

| Herramienta | Versión usada | Comprobar |
|---|---|---|
| PHP (con `pdo_pgsql`) | 8.5 | `php -v` y `php -m \| grep pdo_pgsql` |
| Composer | 2.x | `composer --version` |
| uv (servicios Python, desde el nivel 7) | 0.12 | `uv --version` |
| PostgreSQL | 18 | `psql --version` |
| pgvector para tu PostgreSQL | 0.8 | Ubuntu: `apt install postgresql-18-pgvector` |

El desarrollo local **no usa Docker** ([ADR 0020](docs/adr/0020-postgresql-nativo-en-desarrollo-local.md)): todo corre con las herramientas instaladas en la máquina. Docker queda para producción.

### Pasos

```bash
# 1. Preparar PostgreSQL: rol "hexagonal", bases "hexagonal" y "hexagonal_test"
#    y la extensión pgvector. Necesita el superusuario postgres; se puede
#    repetir sin miedo (es idempotente).
sudo scripts/db-setup.sh

# 2. Instalar y configurar Laravel
cd app-laravel
composer install
cp .env.example .env               # ya apunta al PostgreSQL local (127.0.0.1:5432)
php artisan key:generate

# 3. Crear las tablas
php artisan migrate

# 3b. Gate de calidad: formato + análisis estático + tests
composer ci:check

# 4. Arrancar la aplicación
php artisan serve                  # http://localhost:8000
```

Los servicios Python tienen su propio README con la puesta en marcha y su gate: [`services/importer`](services/importer/README.md) (`uv sync` y `uv run poe serve`, en `http://127.0.0.1:8001`).

Para empezar con la base de desarrollo vacía: `php artisan migrate:fresh`. Para borrarla del todo, `sudo -u postgres dropdb hexagonal` y vuelve al paso 1.

### Versiones elegidas

- **Laravel 13**: la última versión estable cuando empezó el proyecto, y la misma que usan los demás proyectos del autor.
- **Pest 5, Larastan 3 (PHPStan al nivel `max`) y Pint** como gate de calidad (`composer ci:check`).
- **Python 3.14 con uv, FastAPI, ruff, mypy estricto y pytest** en los servicios Python ([ADR 0021](docs/adr/0021-servicio-importer-herramientas-y-estructura.md)); su gate es `uv run poe check`.
- **PostgreSQL 18 con pgvector**, el nativo de la máquina: pgvector hará falta en el bloque C (embeddings), y tenerlo instalado desde ya evita sorpresas cuando llegue.
