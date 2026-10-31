# Explicación HEXM-0001: Esqueleto Laravel y PostgreSQL

## Explicación

Antes de escribir una sola regla de negocio, el proyecto necesita dos cosas: una aplicación que arranque y un sitio donde guardar datos. Esta tarea monta ambas piezas **de forma que la base de datos quede desde el principio como algo externo e intercambiable**: PostgreSQL vive en un contenedor que se levanta con un comando, y Laravel solo lo conoce a través de variables de entorno.

Todavía no hay arquitectura hexagonal en el código (eso empieza en HEXM-0003), pero las decisiones de esta tarea preparan el terreno para ella.

## Lógica de negocio

Esta tarea no implementa ninguna regla de negocio, pero sí responde a necesidades del dominio que vendrán:

- **Los planes nutricionales se acumulan en el tiempo** (plan 25, 26, 27…) y queremos conservar el histórico: hace falta una base de datos relacional que soporte bien consultas por fechas y relaciones (plan → semanas → días → platos). PostgreSQL lo cubre.
- **Más adelante querremos preguntar por significado**, no solo por texto exacto: "¿qué receta corresponde a *Lentejas con pisto*?" o "algo rápido con legumbre". Eso requiere vectores (embeddings), y la extensión **pgvector** permite guardarlos y buscarlos en la misma base de datos. Por eso se elige ya una imagen de PostgreSQL que la incluye.
- **Son datos de salud**: la base de datos solo escucha en `127.0.0.1` y las credenciales son de desarrollo local. Los datos reales del plan nunca se versionan.

## Decisiones de arquitectura

```
   Tu máquina                                   Contenedor Docker
┌──────────────────────────────┐             ┌──────────────────────────┐
│ php artisan serve / migrate  │             │ PostgreSQL 18 + pgvector │
│                              │  TCP 5433   │ (escucha en el 5432)     │
│ Laravel lee .env:            │────────────►│                          │
│  DB_CONNECTION=pgsql         │ 127.0.0.1   │ volumen: postgres-data   │
│  DB_HOST=127.0.0.1           │             └──────────────────────────┘
│  DB_PORT=5433 …              │
└──────────────────────────────┘
```

1. **La base de datos es infraestructura, no el centro.** En hexagonal, el núcleo (dominio y casos de uso) no depende de la base de datos: es la base de datos la que se conecta al núcleo a través de un **adaptador** que implementa un **puerto** (un repositorio). Hoy solo existe Laravel con su configuración por defecto, pero tratar PostgreSQL como un servicio externo que se enciende y se apaga, y que la aplicación conoce solo por configuración, es la misma idea aplicada a nivel de sistema.

2. **Solo se dockeriza la base de datos.** PHP se ejecuta en local porque es lo que se está aprendiendo y depurando a diario: así se ve directamente qué hace `artisan`, sin capas adicionales. Lo que es infraestructura pura (PostgreSQL) va en Docker para no instalarlo ni configurarlo en el sistema. Dockerizar la aplicación completa llegará con el primer despliegue (ADR 0001).

3. **Configuración por entorno.** `.env.example` (versionado) documenta qué variables hacen falta, y `.env` (ignorado por git) tiene los valores reales de cada máquina. El mismo código servirá en local, en CI o en un servidor cambiando solo la configuración.

4. **`app-laravel/` en una subcarpeta y no en la raíz.** El repositorio contendrá más de una aplicación: el núcleo Laravel y, desde el bloque B, servicios Python en `services/`. La raíz queda para lo que es común: documentación, `docker-compose.yml` y CI.

5. **Laravel 13.** La tarea mencionaba Laravel 12, pero la intención era "las últimas versiones", y la 13 es la estable actual y la que usan los demás proyectos del autor. Mantener la misma versión mayor en todos los proyectos reduce el cambio de contexto.

6. **Se eliminaron `app-laravel/CLAUDE.md` y `app-laravel/AGENTS.md`.** El instalador de Laravel 13 los genera con un guion que pide a cualquier agente instalar *Laravel Boost* antes de atender cualquier petición. Las instrucciones del proyecto están en el `CLAUDE.md` raíz, y añadir herramientas es una decisión que pasa por una tarea. Boost queda fuera de momento, por decisión del usuario.

## Alternativas descartadas

- **SQLite (lo que Laravel trae por defecto).** Es cómodo para empezar, pero no tiene pgvector, se comporta distinto de PostgreSQL en tipos, fechas y restricciones, y nos obligaría a migrar más adelante. Mejor trabajar desde el principio con el motor real.
- **PostgreSQL instalado en el sistema** (ya hay uno en el puerto 5432). Mezclaría los datos de este proyecto con los de otros, no se puede borrar y recrear con un comando, y otra persona no podría reproducir el entorno. El contenedor sí es reproducible: lo define un fichero versionado.
- **Laravel Sail** (Docker oficial de Laravel para todo: PHP, base de datos…). Añade una capa entre el usuario y `artisan` (`sail artisan …`) justo cuando lo que interesa es ver Laravel sin intermediarios, e impone su propia estructura de Docker. Un `docker-compose.yml` propio, corto y comentado, se entiende entero.
- **Imagen `postgres:18` oficial sin pgvector.** Habría que cambiarla en el bloque C, y cambiar de imagen con datos dentro es más delicado que elegir la correcta desde el principio. pgvector no molesta mientras no se use.
- **Publicar el puerto en todas las interfaces (`5433:5432`).** Expondría la base de datos a la red local. Con `127.0.0.1:5433:5432` solo se accede desde tu propia máquina.

## Conceptos aplicados

| Concepto | Qué es | Dónde verlo |
|---|---|---|
| Docker Compose | Fichero que describe los servicios que necesita la aplicación para levantarlos con un solo comando | `docker-compose.yml` |
| Volumen con nombre | Almacenamiento de Docker que sobrevive a parar y borrar el contenedor; solo se borra con `down -v` | `docker-compose.yml`, `volumes:` |
| Healthcheck | Comprobación con la que Docker sabe si el servicio está listo de verdad, no solo arrancado | `docker-compose.yml`, `healthcheck:` |
| Mapeo de puertos | `host:contenedor`; aquí `127.0.0.1:5433 → 5432`, para no chocar con el PostgreSQL del sistema ni exponerlo a la red | `docker-compose.yml`, `ports:` |
| Configuración por entorno (twelve-factor) | El código no contiene la configuración: la lee del entorno, así que cambia por máquina sin tocar el código | `app-laravel/.env.example` |
| Infraestructura como detalle | La base de datos es una pieza externa que se conecta a la aplicación, no su centro; es la base de la hexagonal | Esta explicación; se materializa en HEXM-0003 y en el nivel 2 |
| PostgreSQL 18: ruta de datos | Desde la 18, la imagen guarda los datos en `/var/lib/postgresql/18/…`, así que el volumen se monta en `/var/lib/postgresql` | `docker-compose.yml`, comentario de `volumes:` |
