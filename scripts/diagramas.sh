#!/usr/bin/env bash
#
# Regenera los HTML de los diagramas Archify de docs/diagramas a partir de su
# fuente JSON versionada (<slug>.<tipo>.json → <slug>.html).
#
# Por qué existe: los HTML no se versionan (cada uno ocupa ~850 KB porque lleva
# el visor dentro); en git solo está la fuente. Este script los reconstruye en
# local después de clonar o de cambiar de rama.
#
# Uso (desde cualquier carpeta del repositorio):
#   scripts/diagramas.sh                 # todos
#   scripts/diagramas.sh HEXM-0007       # solo los de una tarea
#   ARCHIFY_CLI=/ruta/archify.mjs scripts/diagramas.sh
#
# Usa `archify deliver` (valida, renderiza y comprueba el artefacto), que no
# necesita navegador. La validación completa con navegador (`finalize`) se
# hace al crear el diagrama, en /close-task.

set -euo pipefail

ARCHIFY_CLI="${ARCHIFY_CLI:-$HOME/.agents/skills/archify/bin/archify.mjs}"
ROOT="$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"
FILTER="${1:-}"

if [[ ! -f "$ARCHIFY_CLI" ]]; then
    echo "No se encuentra Archify en $ARCHIFY_CLI (define ARCHIFY_CLI)." >&2
    exit 1
fi

cd "$ROOT"
TYPES='architecture|workflow|sequence|dataflow|lifecycle|erd|class|tree|timeline|waterfall'
count=0
failed=0

while IFS= read -r -d '' source; do
    [[ -n "$FILTER" && "$source" != *"$FILTER"* ]] && continue
    name="$(basename "$source")"
    if [[ ! "$name" =~ ^(.+)\.($TYPES)\.json$ ]]; then
        continue
    fi
    type="${BASH_REMATCH[2]}"
    output="$(dirname "$source")/${BASH_REMATCH[1]}.html"

    if node "$ARCHIFY_CLI" deliver "$type" "$source" "$output" --repo-root "$ROOT" --quality showcase --json > /dev/null; then
        echo "ok    $output"
        count=$((count + 1))
    else
        echo "ERROR $source (ejecuta: node $ARCHIFY_CLI validate $type $source --repo-root . para ver el detalle)" >&2
        failed=$((failed + 1))
    fi
done < <(find docs/diagramas -name '*.json' -print0 | sort -z)

echo "Diagramas generados: $count · con errores: $failed"
[[ "$failed" -eq 0 ]]
