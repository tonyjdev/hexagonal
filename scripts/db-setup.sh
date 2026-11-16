#!/usr/bin/env bash
#
# Prepara el PostgreSQL nativo de la máquina para el proyecto: el rol de la
# aplicación, la base de desarrollo, la de los tests y la extensión pgvector.
#
# Por qué existe: hasta HEXM-0034, PostgreSQL corría en Docker y la imagen
# oficial hacía este trabajo por nosotros (las variables POSTGRES_DB,
# POSTGRES_USER y POSTGRES_PASSWORD creaban el rol y la base, y
# docker-entrypoint-initdb.d creaba hexagonal_test). Ahora el desarrollo local
# no usa Docker (ADR 0020) y este script lo hace explícito.
#
# CONCEPTO: script idempotente
# Se puede ejecutar las veces que haga falta: cada paso comprueba antes si ya
# está hecho (IF NOT EXISTS, \gexec condicional). Ejecutarlo dos veces deja el
# mismo resultado que ejecutarlo una, así que sirve tanto para preparar una
# máquina nueva como para "reparar" una existente.
#
# CONCEPTO: roles y privilegios en PostgreSQL
# El rol de la aplicación (hexagonal) NO es superusuario: es dueño de sus dos
# bases y nada más. Crear roles, bases y extensiones no confiables (pgvector)
# requiere un superusuario, y en Ubuntu lo es el usuario del sistema
# `postgres`, que entra por el socket local con autenticación `peer` (sin
# contraseña: el sistema operativo ya dice quién eres). Por eso el script
# ejecuta psql como `postgres`.
#
# Uso (desde cualquier carpeta del repositorio):
#   sudo scripts/db-setup.sh            # o: pkexec scripts/db-setup.sh
#   sudo -u postgres scripts/db-setup.sh
#   scripts/db-setup.sh                 # si no eres root, pide sudo para postgres
#
# Las credenciales son SOLO de desarrollo local (no son secretos) y deben
# coincidir con app-laravel/.env. Se pueden cambiar con variables de entorno:
#   HEXM_DB_DATABASE (hexagonal) · HEXM_DB_USERNAME (hexagonal)
#   HEXM_DB_PASSWORD (hexagonal)
# La base de los tests es siempre <base>_test (ADR 0002).
#
# Requisito: el paquete de pgvector para tu PostgreSQL
# (Ubuntu: apt install postgresql-18-pgvector).

set -euo pipefail

DB_NAME="${HEXM_DB_DATABASE:-hexagonal}"
DB_USER="${HEXM_DB_USERNAME:-hexagonal}"
DB_PASSWORD="${HEXM_DB_PASSWORD:-hexagonal}"
TEST_DB_NAME="${DB_NAME}_test"

# Cómo ejecutar psql como el superusuario `postgres` según quién lanza el
# script. `runuser` es la forma de cambiar de usuario siendo root sin pedir
# contraseña; desde un usuario normal hace falta sudo.
if [[ "$(id -un)" == "postgres" ]]; then
    PSQL=(psql)
elif [[ "$(id -u)" -eq 0 ]]; then
    PSQL=(runuser -u postgres -- psql)
else
    PSQL=(sudo -u postgres psql)
fi

# El usuario postgres no puede entrar en la carpeta del repositorio (está en
# un home ajeno); psql avisaría al arrancar. /tmp es accesible para todos.
cd /tmp

# ON_ERROR_STOP: el primer error para el script (como `set -e` en bash).
# Los valores viajan como variables de psql (-v) y se citan con :'var'
# (literal) o :"var" (identificador): así psql los escapa por nosotros y no se
# construye SQL concatenando texto.
run_psql() {
    local database="$1"
    shift
    "${PSQL[@]}" --no-psqlrc --quiet -v ON_ERROR_STOP=1 \
        -v db_name="$DB_NAME" -v test_db_name="$TEST_DB_NAME" \
        -v db_user="$DB_USER" -v db_password="$DB_PASSWORD" \
        --dbname "$database" "$@"
}

echo "→ Rol ${DB_USER} y bases ${DB_NAME} y ${TEST_DB_NAME}"
run_psql postgres <<'SQL'
-- CREATE ROLE no admite IF NOT EXISTS, y CREATE DATABASE no puede ir dentro
-- de una transacción ni de un bloque DO. El truco idempotente: un SELECT que
-- genera la sentencia solo si hace falta, y \gexec la ejecuta.
SELECT format('CREATE ROLE %I LOGIN', :'db_user')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'db_user')
\gexec

-- La contraseña se fija siempre: si cambió en el entorno, se actualiza.
ALTER ROLE :"db_user" WITH LOGIN PASSWORD :'db_password';

SELECT format('CREATE DATABASE %I OWNER %I', :'db_name', :'db_user')
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = :'db_name')
\gexec

SELECT format('CREATE DATABASE %I OWNER %I', :'test_db_name', :'db_user')
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = :'test_db_name')
\gexec
SQL

# Las extensiones son de cada base, no del servidor: se activan en las dos.
# pgvector aún no se usa (llegará en el bloque C), pero tenerla ya en la base
# de los tests evita sorpresas cuando aparezca la primera migración que la use.
for database in "$DB_NAME" "$TEST_DB_NAME"; do
    echo "→ Extensión vector en ${database}"
    # client_min_messages: sin el NOTICE "already exists" en las ejecuciones
    # siguientes, que no es un problema sino justo lo esperado.
    run_psql "$database" \
        -c 'SET client_min_messages = warning;' \
        -c 'CREATE EXTENSION IF NOT EXISTS vector;'
done

echo "✓ PostgreSQL listo para el proyecto"
