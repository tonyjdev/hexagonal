# Explicación HEXM-0034: PostgreSQL nativo en local y retirada de Docker Compose

## Explicación

Desde HEXM-0001, PostgreSQL corría en un contenedor de Docker Compose (`pgvector/pgvector:pg18`, puerto 5433) y PHP en local. Al preparar el nivel 7, que iba a añadir el primer servicio Python a ese mismo Compose, surgió la pregunta: **¿hay algo que obligue técnicamente a usar Docker en este proyecto?**

La respuesta fue que no:

- la aplicación solo lee `DB_HOST` y `DB_PORT` de `.env`; no sabe si detrás hay un contenedor;
- no hay CI ni código que dependa de Docker;
- la máquina ya tenía PostgreSQL 18 nativo (la misma versión mayor que la imagen), y pgvector está en los repositorios del sistema;
- lo que hacía la imagen por nosotros era poco: crear el rol, la base de desarrollo y la de los tests.

Además, el sistema de tareas compartido de los proyectos del autor (workspace-kit), al que se migrará este repositorio, fija como estándar no usar Docker en desarrollo local. Mantenerlo habría exigido documentar una excepción sin razón técnica.

La solución: PostgreSQL pasa a ser el **nativo de la máquina**, preparado por un **script idempotente** (`scripts/db-setup.sh`) que hace explícito lo que antes escondía la imagen, y se retiran `docker-compose.yml` y `docker/`. Docker queda para producción. La decisión está en el [ADR 0020](../adr/0020-postgresql-nativo-en-desarrollo-local.md).

## Lógica de negocio

No hay reglas de negocio nuevas ni cambiadas: el plan 27, sus semanas, el recetario y las 24 reglas de `docs/DOMINIO.md` funcionan exactamente igual. Esta tarea solo cambia **dónde vive la base de datos mientras se desarrolla**.

Eso es precisamente lo que demuestra: que la nutricionista entregue un plan nuevo, o que «¿qué me toca hoy?» devuelva las lentejas del martes, no depende de si PostgreSQL corre en un contenedor o en el sistema. Los 564 tests, que recorren esas reglas de punta a punta contra PostgreSQL, pasan sin cambiar una línea.

## Decisiones de arquitectura

### Qué hacía la imagen y quién lo hace ahora

| Antes (imagen `pgvector/pgvector:pg18`) | Ahora (PostgreSQL nativo) |
|---|---|
| `POSTGRES_USER`/`POSTGRES_PASSWORD` creaban el rol `hexagonal` | `scripts/db-setup.sh`: `CREATE ROLE` (si no existe) y `ALTER ROLE … PASSWORD` |
| `POSTGRES_DB` creaba la base `hexagonal` | `scripts/db-setup.sh`: `CREATE DATABASE hexagonal OWNER hexagonal` (si no existe) |
| `docker-entrypoint-initdb.d/01-create-test-database.sh` creaba `hexagonal_test`, **solo la primera vez** que se creaba el volumen | `scripts/db-setup.sh`, **cada vez que se ejecute** (si falta) |
| pgvector venía instalado en la imagen | paquete del sistema `postgresql-18-pgvector` + `CREATE EXTENSION IF NOT EXISTS vector` en las dos bases |
| Puerto 5433 en el host (para no chocar con el PostgreSQL del sistema) | Puerto 5432, el del PostgreSQL del sistema |

Un detalle que mejora: el script de inicio de la imagen solo se ejecutaba al crear el volumen, por eso el README tenía un paso manual para volúmenes antiguos (`docker compose exec postgres createdb …`). El script nuevo se puede ejecutar siempre, así que ese paso desaparece.

### Por qué el script es idempotente

Un script de entorno se ejecuta en máquinas en estados distintos: una recién instalada, otra donde ya existe el rol pero falta la base de los tests, otra completa. Si cada paso comprueba antes si ya está hecho, **ejecutarlo dos veces deja lo mismo que ejecutarlo una**, y el mismo script sirve para preparar y para reparar.

PostgreSQL no lo pone fácil: `CREATE ROLE` y `CREATE DATABASE` no admiten `IF NOT EXISTS`, y `CREATE DATABASE` no puede ir dentro de un bloque `DO` (ni de ninguna transacción). El truco es que un `SELECT` **genere la sentencia** solo si hace falta y `\gexec` de psql la ejecute:

```sql
SELECT format('CREATE DATABASE %I OWNER %I', :'test_db_name', :'db_user')
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = :'test_db_name')
\gexec
```

Los valores llegan como variables de psql (`-v`) y se citan con `:'var'` (literal) o `:"var"` (identificador): psql los escapa, y no se construye SQL concatenando texto.

### Dos usuarios con papeles distintos

- **`postgres`** (superusuario) prepara el terreno: crear roles, bases y una extensión no confiable como pgvector lo exige. En Ubuntu entra por el socket local con autenticación **`peer`**: no hay contraseña, el sistema operativo ya dice quién eres. Por eso el script ejecuta psql como `postgres` (con `runuser` si se lanza como root, con `sudo -u postgres` si no).
- **`hexagonal`** (la aplicación) **no es superusuario**: es dueño de sus dos bases y nada más, y entra por TCP con contraseña. Es el *principio de mínimo privilegio*: la aplicación puede hacer todo en sus bases (migraciones incluidas) y nada fuera de ellas.

Las extensiones son **de cada base**, no del servidor: por eso `vector` se activa en `hexagonal` y en `hexagonal_test`.

### Qué ha cambiado en la aplicación (casi nada, y eso es lo importante)

```
Dominio y aplicación (src/)          → sin cambios
Adaptadores (Eloquent, HTTP…)        → sin cambios
Configuración (.env, DB_PORT)        → 5433 → 5432
Entorno (cómo se prepara la base)    → Docker Compose → scripts/db-setup.sh
```

El ADR 0002 sigue vigente en lo esencial: los tests usan PostgreSQL real, en la base separada `hexagonal_test`, fijada en `phpunit.xml` y protegida por `tests/Feature/TestDatabaseTest.php`. Solo cambia dónde vive esa base, y se ha anotado al principio del ADR.

### Docker, para producción

El ADR 0001 ya preveía construir una imagen por commit cuando haya despliegue. Eso sigue así: los ficheros de Docker volverán con el primer despliegue, para producción. Y el servicio Python del nivel 7 (HEXM-0028) se ejecutará en local con su entorno virtual.

## Alternativas descartadas

- **Mantener Docker Compose solo para PostgreSQL**, como excepción documentada. Funciona, pero la excepción no tendría razón técnica, y el nivel 7 empujaba a meter más servicios en el Compose.
- **Un clúster de PostgreSQL aparte** (`pg_createcluster 18 hexagonal --port 5433`) para aislar el proyecto. Es más aislamiento del necesario: un rol y unas bases propios ya separan el proyecto del resto, y un único clúster es más sencillo de mantener y actualizar.
- **Mantener el puerto 5433** para no tocar `.env`. Sin contenedor no hay ningún conflicto que evitar, y 5432 es el puerto por defecto de PostgreSQL (y el que Laravel trae por defecto).
- **Traspasar los datos del contenedor** con `pg_dump`/`pg_restore`. La base de desarrollo estaba vacía (0 recetas, 0 planes): bastó con `php artisan migrate` en la base nativa.
- **En un Laravel clásico** se usaría Laravel Sail (Docker) o Herd/Valet. Sail es justo lo que se deja; Herd en Linux no aplica y Valet no gestiona PostgreSQL. Un script propio de pocas líneas es más transparente y enseña lo que hay debajo.

## Conceptos aplicados

- **Script idempotente**: repetirlo no cambia el resultado. Ver `scripts/db-setup.sh` (`\gexec` condicional, `IF NOT EXISTS`).
- **Roles, dueños y extensiones en PostgreSQL**: el rol de la aplicación es dueño de sus bases; las extensiones se activan en cada base. Ver `scripts/db-setup.sh`.
- **Autenticación `peer` frente a contraseña**: el superusuario entra por el socket con la identidad del sistema; la aplicación, por TCP con contraseña. Ver la cabecera de `scripts/db-setup.sh`.
- **Mínimo privilegio**: la aplicación nunca usa el superusuario. Ver ADR 0020, punto 2.
- **Configuración por entorno (twelve-factor)**: mover la base de sitio solo cambia `DB_PORT`. Ver `app-laravel/.env.example`.
- **La base de datos como detalle de infraestructura**: el núcleo hexagonal no se ha enterado del cambio. Ver ADR 0020, «Consecuencias».

## Diagramas

Ninguno: la tarea cambia la configuración del entorno de desarrollo (dónde vive PostgreSQL y cómo se prepara), no un flujo de la aplicación, una regla de negocio ni la estructura del núcleo. La tabla «Qué hacía la imagen y quién lo hace ahora» cumple el papel que tendría un diagrama.
