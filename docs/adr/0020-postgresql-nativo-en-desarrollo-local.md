# 0020. PostgreSQL nativo en desarrollo local; Docker solo en producción

**Fecha:** 2026-11-16 · **Estado:** aceptada · **Tarea:** HEXM-0034 · **Actualiza:** [ADR 0002](0002-base-de-datos-de-los-tests.md) (dónde vive la base de los tests)

## Contexto

En HEXM-0001 se levantó PostgreSQL con **Docker Compose** (`docker-compose.yml`, imagen `pgvector/pgvector:pg18`, puerto 5433) y PHP en local. La razón: arrancar la base con un solo comando, sin instalar nada en el sistema, y con pgvector ya incluido en la imagen.

Al preparar el nivel 7 (el primer servicio Python) se revisó qué aporta Docker de verdad al desarrollo:

- **Nada técnico depende de él.** La aplicación solo lee `DB_HOST`/`DB_PORT` de `.env`; no hay CI ni código que hable con Docker. El contenedor solo ponía la base de datos.
- **La máquina ya tiene PostgreSQL 18 nativo**, la misma versión mayor que la imagen, y pgvector está en los repositorios del sistema (`postgresql-18-pgvector`).
- **Lo que hacía la imagen por nosotros es poco y explícito:** crear el rol y la base (variables `POSTGRES_*`) y la base de los tests (`docker-entrypoint-initdb.d`).
- El **sistema de tareas compartido** de los proyectos del autor (workspace-kit) fija como estándar no usar Docker en desarrollo local, y este repositorio se migrará a él. Mantener Docker obligaría a documentar una excepción sin una razón técnica que la sostenga, y además el nivel 7 iba a añadir el servicio Python al Compose.

## Decisión

1. **El desarrollo local no usa Docker.** PostgreSQL es el nativo de la máquina (`127.0.0.1:5432`), con pgvector instalado como paquete del sistema. Los servicios Python se ejecutarán con su entorno virtual.
2. **`scripts/db-setup.sh`** prepara la base: crea el rol `hexagonal` (con contraseña, sin ser superusuario), las bases `hexagonal` y `hexagonal_test` (de las que es dueño) y la extensión `vector` en las dos. Es **idempotente**: se puede repetir sin efectos nuevos. Se ejecuta como el superusuario `postgres` (`sudo scripts/db-setup.sh`), porque crear roles, bases y una extensión no confiable lo requiere; la aplicación nunca usa ese superusuario.
3. Se eliminan `docker-compose.yml` y `docker/postgres/`. `app-laravel/.env.example` pasa al puerto 5432. El resto del ADR 0002 (PostgreSQL real en los tests, base `hexagonal_test` separada, `phpunit.xml` y el test que lo protege) no cambia.
4. **Docker queda para producción:** cuando llegue el primer despliegue se añadirán sus ficheros (un `Dockerfile` por aplicación o servicio, en `docker/` o en la raíz), con la idea de «una imagen por commit» del [ADR 0001](0001-estrategia-de-ramas.md).

### Alternativas descartadas

- **Mantener Docker Compose solo para PostgreSQL**, documentado como excepción: funciona, pero la excepción no tendría razón técnica, y cada servicio nuevo (FastAPI) empujaría a meter más cosas en el Compose.
- **Un clúster de PostgreSQL aparte** (`pg_createcluster` en otro puerto) para aislar el proyecto del resto: más aislamiento del necesario. Un rol y unas bases propios ya separan el proyecto, y el clúster único es más fácil de mantener.

## Consecuencias

- **A favor:** un requisito menos (Docker) para levantar el proyecto; la base arranca con el sistema; se ve y se aprende lo que antes escondía la imagen (roles, dueños de bases, extensiones por base, autenticación `peer` del superusuario frente a contraseña de la aplicación); el repositorio cumple el estándar del sistema de tareas.
- **En contra:** la versión de PostgreSQL y de pgvector la marca el sistema, no un fichero versionado; quien clone el proyecto en otra máquina tiene que instalarlos (el README lo indica). Preparar la base pide privilegios de administrador una vez.
- **Reinicio de datos:** antes era `docker compose down -v`; ahora, `php artisan migrate:fresh` para vaciar las tablas, o `dropdb` y de nuevo `scripts/db-setup.sh` para empezar de cero.
- **Para la hexagonal:** mover la base de datos de sitio solo ha tocado configuración (`DB_PORT`) y un script de entorno; ni el dominio ni los adaptadores han cambiado. Es el «la base de datos es un detalle de infraestructura» llevado a la práctica.
