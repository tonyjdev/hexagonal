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
| Docker + Docker Compose | 29 / 2.40 | `docker compose version` |

### Pasos

```bash
# 1. Levantar PostgreSQL (puerto 5433 de tu máquina)
docker compose up -d
docker compose ps                  # esperar a que salga "(healthy)"

# 2. Instalar y configurar Laravel
cd app-laravel
composer install
cp .env.example .env               # ya viene configurado para el PostgreSQL de Docker
php artisan key:generate

# 3. Crear las tablas
php artisan migrate

# 3b. Base de datos de los tests (solo si tu volumen de Docker ya existía
#     antes de HEXM-0002; en volúmenes nuevos se crea sola)
docker compose exec postgres createdb -U hexagonal hexagonal_test

# 3c. Gate de calidad: formato + análisis estático + tests
composer ci:check

# 4. Arrancar la aplicación
php artisan serve                  # http://localhost:8000
```

Para parar la base de datos: `docker compose down` (conserva los datos) o `docker compose down -v` (los borra).

### Versiones elegidas

- **Laravel 13**: la última versión estable cuando empezó el proyecto, y la misma que usan los demás proyectos del autor.
- **Pest 5, Larastan 3 (PHPStan al nivel `max`) y Pint** como gate de calidad (`composer ci:check`).
- **PostgreSQL 18 con pgvector** (`pgvector/pgvector:pg18`): pgvector hará falta en el bloque C (embeddings), y usar ya esa imagen evita migrar de imagen más adelante.
