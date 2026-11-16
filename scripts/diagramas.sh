#!/usr/bin/env bash
#
# Regenera los HTML de los diagramas Archify de docs/diagramas a partir de su
# fuente JSON versionada (<slug>.<tipo>.json).
#
# Por qué existe: los HTML no se versionan (cada uno ocupa ~850 KB porque lleva
# el visor dentro); en git solo está la fuente. Desde HEXM-0035 el repositorio
# usa el sistema de tareas compartido (workspace-kit), y los HTML se generan
# FUERA del proyecto, en una caché común a todos los worktrees:
#   ~/.cache/workspace-kit/diagramas/hexagonal/HEXM-NNNN/<slug>.html
# Así un worktree de tarea nunca deja ficheros generados en docs/diagramas.
#
# Este script es solo un atajo con nombre en español (y el destino de
# `composer docs:diagrams`) para el script compartido del kit, que nació de
# una versión anterior de este mismo fichero.
#
# Uso (desde cualquier carpeta del repositorio):
#   scripts/diagramas.sh                 # todos (imprime la ruta de cada HTML)
#   scripts/diagramas.sh HEXM-0007       # solo los de una tarea
#   scripts/diagramas.sh --print-dir     # la carpeta de la caché
#   ARCHIFY_CLI=/ruta/archify.mjs scripts/diagramas.sh

set -euo pipefail

KIT_DIAGRAMS="${WORKSPACE_KIT:-$HOME/.agents/workspace-kit}/bin/diagrams.sh"

if [[ ! -x "$KIT_DIAGRAMS" ]]; then
    echo "No se encuentra el script del kit en $KIT_DIAGRAMS (define WORKSPACE_KIT)." >&2
    exit 1
fi

cd "$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"
exec "$KIT_DIAGRAMS" "$@"
