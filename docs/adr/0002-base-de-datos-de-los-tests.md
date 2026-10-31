# 0002. Base de datos de los tests: PostgreSQL en `hexagonal_test`

**Fecha:** 2026-10-31 · **Estado:** aceptada · **Tarea:** HEXM-0002 (resuelve la deuda técnica #190)

## Contexto

Laravel configura por defecto los tests sobre **SQLite en memoria** (`phpunit.xml`): es rápido y no necesita servicios. Pero la aplicación usa **PostgreSQL**, y los dos motores difieren en tipos, restricciones, fechas con zona horaria, funciones JSON y, sobre todo, en extensiones: en el bloque C usaremos **pgvector**, que SQLite no tiene.

Un test que pasa en SQLite no garantiza que el código funcione en PostgreSQL.

## Decisión

1. Los tests que necesitan base de datos usan **PostgreSQL**, en una base de datos propia: **`hexagonal_test`**, dentro del mismo contenedor de desarrollo.
   - Base de datos separada porque los tests borran y recrean las tablas (`RefreshDatabase`): si usaran `hexagonal`, cada ejecución borraría los datos de desarrollo.
   - Mismo contenedor para no levantar un segundo servicio.
2. `hexagonal_test` se crea:
   - automáticamente, con `docker/postgres/init/01-create-test-database.sh`, la primera vez que se crea el volumen;
   - a mano en volúmenes que ya existían: `docker compose exec postgres createdb -U hexagonal hexagonal_test`.
3. `phpunit.xml` fija `DB_CONNECTION=pgsql` y `DB_DATABASE=hexagonal_test`; el host, el puerto y las credenciales vienen de `.env`.
4. El test `tests/Feature/TestDatabaseTest.php` **protege la decisión**: falla si los tests dejan de ejecutarse sobre `pgsql`/`hexagonal_test`.
5. Los **tests de dominio** (`tests/Unit`) no usan base de datos: el dominio hexagonal no depende de la persistencia, así que se prueba sin ella.

## Consecuencias

- **A favor:** los tests prueban el motor real; no hay sorpresas al desplegar; pgvector estará disponible en los tests cuando haga falta.
- **En contra:** los tests de integración necesitan el contenedor levantado (`docker compose up -d`). Si no lo está, fallan con un error de conexión claro. En CI habrá que levantar PostgreSQL como servicio, lo que se hará cuando exista el pipeline.
- **Mitigación del coste:** la mayoría de los tests serán de dominio (sin base de datos, en milisegundos). Solo los adaptadores de persistencia necesitan PostgreSQL.
