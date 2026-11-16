# Explicación HEXM-0035: Migrar el repositorio al sistema de tareas compartido

## Explicación

Hasta ahora este repositorio tenía **su propio sistema de tareas**: tres skills (`start-task`, `close-task`, `create-task`) en `.claude/skills/`, la configuración en un bloque de `CLAUDE.md` y un flujo pensado para un solo agente. Ese flujo creaba la rama en el checkout principal y no usaba worktrees. Mientras, el resto de proyectos del autor fueron pasando a un **sistema compartido** (workspace-kit, en `~/.agents/workspace-kit`), con skills globales y estándares comunes. En este repositorio las skills globales funcionaban en modo «legacy»: detectaban que no estaba migrado y delegaban en las antiguas.

Con Docker fuera del desarrollo local (HEXM-0034), al repositorio solo le faltaban tres cosas para cumplir los estándares: la rama `staging`, su configuración para el kit y separar lo propio del proyecto (`AGENTS.md`) de lo común. Esta tarea hace la migración. Desde ahora `/init-task`, `/start-task`, `/close-task`, las revisiones y los despliegues usan el flujo común, y el repositorio solo aporta:

- **su configuración**, en `.claude/task-system.conf`;
- **lo que le es propio**, en `AGENTS.md`: el carácter didáctico, la copia de la Explicación en `docs/explicaciones/` y la documentación viva al cerrar.

## Lógica de negocio

No hay reglas de negocio nuevas ni cambiadas. El plan 27, el recetario y las 24 reglas de `docs/DOMINIO.md` siguen igual, y los 564 tests de Laravel y los 2 del importer pasan sin tocar una línea de código de la aplicación.

Lo que cambia es **cómo se organiza el trabajo** sobre ese dominio. Se nota en la próxima tarea real, HEXM-0029 («leer el Excel de la nutricionista»):

- **Antes**, HEXM-0029 se habría trabajado cambiando de rama en la carpeta del proyecto. Mientras tanto, la aplicación que responde «¿qué me toca hoy?» habría dejado de ser la de `develop`.
- **Ahora**, `/init-task HEXM-0029` le crea su propia carpeta (`.worktrees/hexm-0029`). La raíz sigue en `develop`, sirviendo el menú de hoy, y se puede revisar otra tarea a la vez sin tocar la que está a medias.

## Decisiones de arquitectura

### Qué pone el repositorio y qué pone el kit

```
~/.agents/workspace-kit (común a todos los proyectos)     hexagonal (solo lo propio)
├── skills globales: init/start/close-task, revisiones,   ├── .claude/task-system.conf  ← configuración
│   create-task, deploy-staging/production                ├── AGENTS.md                 ← reglas propias
├── bin/task-worktree.sh  (ramas y worktrees, doctor)     ├── CLAUDE.md = @AGENTS.md
├── bin/manage_workspace_task.sh (CLI de Workspace)       ├── scripts/worktree-setup.sh ← preparar un worktree
├── bin/diagrams.sh (HTML de los diagramas, en caché)     └── scripts/diagramas.sh      ← atajo a diagrams.sh
└── AGENTS.shared.md (reglas comunes)
```

Es la misma idea que la arquitectura del propio proyecto, aplicada a las herramientas. El kit es el **mecanismo común**: igual que el núcleo, no sabe nada de este repositorio. El repositorio solo lo **configura**, con sus valores (`task-system.conf`) y sus reglas (`AGENTS.md`). Cuando el kit mejora, todos los proyectos mejoran a la vez, sin copiar skills de uno a otro. Esa copia es lo que pasaba antes: `scripts/diagramas.sh` nació aquí y el kit lo generalizó como `bin/diagrams.sh`.

### La configuración (`.claude/task-system.conf`)

| Clave | Valor | Por qué |
|---|---|---|
| `CLOSE_CHECK_CMD` | los dos gates: `composer ci:check` y `uv run poe check` | Desde HEXM-0028 hay dos aplicaciones, y una tarea no se integra si rompe cualquiera de las dos. La descripción de la tarea solo nombraba el de Laravel, y se corrigió. |
| `RELEASE_CHECK_CMD` | los dos gates | `/deploy-staging` los vuelve a ejecutar sobre `develop` antes de sellar una versión. |
| `SETUP_CMD` | `bash scripts/worktree-setup.sh` | Un worktree recién creado no tiene `vendor/`, `.venv/` ni `.env` (sección siguiente). |
| `POST_MERGE_CMD` | `composer install` + `migrate` y `uv sync` en el checkout principal | Después de cada merge, la aplicación que sirve `develop` queda al día: dependencias nuevas y migraciones aplicadas a la base de datos de desarrollo. |
| `CHANGELOG` | `none` | No hay versiones publicadas ni usuarios externos. El historial del roadmap y el diario cuentan la evolución. Se puede pasar a `kit` con el primer despliegue. |
| `CI_WAIT` | `none` | Todavía no hay CI en GitHub (fuera de alcance). |

### Preparar un worktree (`scripts/worktree-setup.sh`)

Un worktree comparte el historial con el checkout principal, pero no lo que git ignora. El kit copia el `.env` de la raíz, pero aquí cada aplicación tiene su configuración en su carpeta (`app-laravel/.env`). Por eso el script hace tres cosas:

1. ejecuta `composer install` primero, porque `artisan` necesita `vendor/`;
2. copia `app-laravel/.env` del checkout principal; sin él, parte de `.env.example` y genera una clave;
3. ejecuta `uv sync --frozen` en el importer, con las versiones exactas de `uv.lock`.

El checkout principal lo localiza con git, no con una ruta fija: el directorio común de git es el `.git` del checkout principal, y su carpeta padre es el propio checkout. Además, **no ejecuta `migrate`**: todos los worktrees comparten las bases `hexagonal` y `hexagonal_test`, y migrar desde una tarea a medias cambiaría la base de datos de la que vive `develop`. Las migraciones llegan a desarrollo después del merge, con `POST_MERGE_CMD`. Los tests crean su propio esquema.

### Las tres ramas desde ya

El estándar del kit exige `develop`, `staging` y `main` en todos los proyectos. El ADR 0001 ya había elegido ese modelo, pero dejaba `staging` para el primer despliegue. Ahora se crea desde `main`. No despliega en ningún entorno, igual que `main`, pero las promociones ya siguen el camino definitivo con `/deploy-staging` y `/deploy-production`. Lo recoge la actualización del [ADR 0001](../adr/0001-estrategia-de-ramas.md).

### Los diagramas, fuera del proyecto

Antes el HTML de cada diagrama (~850 KB) se generaba al lado de su JSON, en `docs/diagramas/`, ignorado por git. Con worktrees eso supondría un juego de HTML por carpeta de tarea. El kit los genera en una **caché común**, `~/.cache/workspace-kit/diagramas/hexagonal/`, y en el proyecto solo vive la fuente. `composer docs:diagrams` sigue funcionando igual porque `scripts/diagramas.sh` ahora llama al script del kit. Los 45 diagramas existentes se regeneraron allí sin errores. El de HEXM-0008 también: cita la skill `close-task` que esta tarea borra, pero Archify valida las líneas en el commit fijo del diagrama, donde la skill sigue existiendo.

## Alternativas descartadas

- **Mantener `staging` para más adelante y documentar una excepción.** El kit no la admite: el estándar de ramas no es configurable. Mantener un flujo distinto en un solo proyecto es justo lo que el sistema compartido quiere evitar.
- **Seguir sin worktrees** («un solo agente, más fácil de seguir»). El coste de un worktree es un `composer install` y un `uv sync`, que el script automatiza. A cambio, el checkout principal no cambia nunca de rama, y una tarea a medias no bloquea una revisión ni una consulta a la aplicación.
- **Conservar las skills propias del repositorio.** Las globales tienen prioridad sobre las del proyecto: con el repositorio migrado, las antiguas no se ejecutarían nunca y solo confundirían. Lo que tenían de propio pasa a `AGENTS.md`: las convenciones didácticas, la copia de la Explicación, las pruebas manuales paso a paso, la documentación viva y la etiqueta `nivel-NN`.
- **Seguir generando los HTML en `docs/diagramas/`.** Habría que generarlos en cada worktree, y `doctor` avisaría de ficheros generados dentro del proyecto. La caché es una sola para todas las carpetas.
- **Changelog del kit (`CHANGELOG=kit`) desde ya.** Escribir una entrada por tarea sin versiones ni usuarios que la lean sería burocracia. El historial del roadmap ya cumple esa función mientras no haya despliegue.
- **`migrate` en `SETUP_CMD`.** Aplicaría las migraciones de una tarea sin integrar a la base de datos que usa `develop` (explicado arriba).

## Conceptos aplicados

| Concepto | Definición breve | Dónde verlo |
|---|---|---|
| Git worktree | Otra carpeta de trabajo del mismo repositorio, con su propia rama sacada. Comparte el historial, pero no lo que git ignora. | `scripts/worktree-setup.sh`, `.gitignore` (`/.worktrees`), `docs/TAREAS.md` §4 |
| Promoción entre ramas de entorno | El código avanza `develop` → `staging` → `main` por PR, y cada rama marca un entorno. | ADR 0001 (actualización), `docs/TAREAS.md` §4 |
| Mecanismo común y configuración por proyecto | Las herramientas viven en un solo sitio; cada repositorio declara sus valores y sus reglas, sin copiar código. | `.claude/task-system.conf`, `AGENTS.md`, `CLAUDE.md` |
| Checkout principal estable | La raíz del repositorio se queda en `develop` y sirve la aplicación; las tareas viven en sus worktrees. | `docs/TAREAS.md` §4 |
| Script idempotente | Ejecutarlo dos veces deja lo mismo que una: no pisa un `.env` existente y las instalaciones se repiten sin efecto. | `scripts/worktree-setup.sh` |
| Artefactos generados fuera del proyecto | Lo que se puede reconstruir no se versiona ni se guarda en el repositorio: se regenera en una caché. | `scripts/diagramas.sh`, `docs/diagramas/README.md` |

## Diagramas

Sin diagramas: la tarea cambia la configuración y la documentación del flujo de trabajo, no el código de la aplicación. El reparto entre el kit y el repositorio se ve en el esquema ASCII de «Decisiones de arquitectura». Los diagramas existentes se ven con `cd app-laravel && composer docs:diagrams`, que ahora los genera en `~/.cache/workspace-kit/diagramas/hexagonal/`.
