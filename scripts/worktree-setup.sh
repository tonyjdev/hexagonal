#!/usr/bin/env bash
#
# Prepara un worktree de tarea recién creado para poder trabajar y pasar el gate.
#
# CONCEPTO: Git worktree
# Un worktree es otra carpeta de trabajo del MISMO repositorio, con su propia
# rama sacada (`.worktrees/hexm-NNNN`). Comparte el historial (.git) con el
# checkout principal, pero no lo que git ignora: ni `vendor/`, ni `.venv/`, ni
# los `.env`. Por eso, recién creado, no puede ejecutar nada.
#
# Quién lo ejecuta: `task-worktree.sh init` (de /init-task), como SETUP_CMD de
# `.claude/task-system.conf`, dentro del worktree nuevo. El kit ya copia el
# `.env` de la raíz, pero en este repositorio cada aplicación tiene el suyo
# (`app-laravel/.env`), así que lo copiamos aquí desde el checkout principal.
#
# Los worktrees comparten la base de datos local (hexagonal y hexagonal_test):
# no se ejecuta `migrate` aquí para no tocar la base de datos de desarrollo que
# está sirviendo el checkout principal. Los tests crean su esquema solos.
#
# Se puede repetir sin miedo: no sobrescribe un `.env` que ya exista.

set -euo pipefail

ROOT="$(git rev-parse --show-toplevel)"
# El directorio común de git es el `.git` del checkout principal; su carpeta
# padre es el propio checkout principal (también desde un worktree).
PRIMARY="$(dirname "$(git -C "$ROOT" rev-parse --path-format=absolute --git-common-dir)")"

cd "$ROOT"

# 1. Laravel: dependencias (primero: `artisan` las necesita) y configuración local.
(cd app-laravel && composer install --no-interaction --no-progress)
if [[ ! -f app-laravel/.env ]]; then
    if [[ -f "$PRIMARY/app-laravel/.env" ]]; then
        echo "worktree-setup: copiando app-laravel/.env del checkout principal"
        cp -- "$PRIMARY/app-laravel/.env" app-laravel/.env
    else
        echo "worktree-setup: no hay app-laravel/.env en el checkout principal; se usa .env.example"
        cp -- app-laravel/.env.example app-laravel/.env
        (cd app-laravel && php artisan key:generate --no-interaction)
    fi
fi

# 2. Servicio importer (Python): entorno virtual con las versiones de uv.lock.
(cd services/importer && uv sync --frozen)

echo "worktree-setup: listo"
