#!/usr/bin/env bash
#
# Crea la base de datos de los tests (hexagonal_test) junto a la de desarrollo.
#
# CONCEPTO: scripts de inicialización de la imagen de PostgreSQL
# La imagen oficial ejecuta, por orden alfabético, los scripts de
# /docker-entrypoint-initdb.d SOLO la primera vez, cuando el volumen de datos
# está vacío. Si el volumen ya existía, este script no se ejecuta y la base de
# datos de tests se crea a mano (ver README, "Puesta en marcha"):
#
#     docker compose exec postgres createdb -U hexagonal hexagonal_test
#
# Por qué una base de datos separada: los tests borran y recrean las tablas
# (RefreshDatabase). Si usaran la de desarrollo, cada ejecución de los tests
# borraría tus datos. Ver docs/adr/0002-base-de-datos-de-los-tests.md.
set -euo pipefail

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-SQL
    CREATE DATABASE ${POSTGRES_DB}_test OWNER "$POSTGRES_USER";
SQL
