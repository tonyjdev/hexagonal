---
name: start-task
description: "Empieza una tarea HEXM de Workspace: la selecciona, carga su descripción y documentos asociados, crea la rama feat/hexm-NNNN-slug desde develop, la pasa a in_progress y prepara la implementación con las convenciones didácticas del proyecto. Usar al comenzar a trabajar en una tarea de este repositorio."
---

# Start Task

Prepara e inicia una tarea del proyecto `HEXM`. Ejecuta tú mismo cada paso con Bash; no lo delegues en un subagente.

## Configuración

Lee el bloque de configuración de la sección `## Task System` de `CLAUDE.md`. Detalle del proceso en `docs/TAREAS.md`.

## Pasos

### 1. Seleccionar la tarea

Si no se indica la clave, lista las candidatas y **pregunta al usuario cuál empezar** (no elijas tú):

```bash
{WORKSPACE_CLI} list --workspace {WORKSPACE_PATH} --project-key HEXM --status open,backlog,suggested --limit 20
```

Lee la tarea:

```bash
{WORKSPACE_CLI} show --workspace {WORKSPACE_PATH} --key {TASK_KEY}
```

### 2. Cargar los documentos asociados

```bash
{WORKSPACE_CLI} documents --workspace {WORKSPACE_PATH} --key {TASK_KEY}
```

Forman parte de la fuente de la tarea. Presta atención a `domain_issue` (decisiones de negocio pendientes que pueden bloquear), `tech_debt` y `watchlist`. Si Workspace no responde, PARA e informa del error.

### 3. Crear la rama

El árbol de trabajo debe estar limpio y en `develop`. Si no lo está, PARA e informa.

```bash
git switch {BRANCH_DEVELOP} && git pull --ff-only
git switch -c {BRANCH_FEATURE_PREFIX}{task-key-en-minúsculas}-{slug}
```

El *slug* se obtiene del título: minúsculas, sin acentos ni signos, con las palabras unidas por guiones (`Esqueleto Laravel y PostgreSQL` → `esqueleto-laravel-y-postgresql`). Ejemplo: `feat/hexm-0001-esqueleto-laravel-y-postgresql`.

Si la rama ya existe porque la tarea se había empezado, cámbiate a ella en lugar de crearla.

### 4. Pasar la tarea a `in_progress`

```bash
{WORKSPACE_CLI} transition --workspace {WORKSPACE_PATH} --user-email {WORKSPACE_USER_EMAIL} --key {TASK_KEY} --status in_progress
```

Si Workspace rechaza la transición (por ejemplo, desde `backlog` hay que pasar antes por `open`), haz primero la transición intermedia. Si sigue fallando, PARA e informa del estado actual.

### 5. Cargar el contexto del proyecto

Lee `CLAUDE.md`, `docs/CONVENCIONES.md`, `docs/DOMINIO.md` y el nivel correspondiente de `docs/ROADMAP.md`.

### 6. Informar y planificar

Antes de escribir código, explica al usuario en español:

- el objetivo de la tarea y su lugar en el roadmap;
- el plan en pasos pequeños;
- qué conceptos nuevos van a aparecer (de arquitectura, de Laravel, de Python o de IA).

## Durante la implementación

- Sigue `docs/CONVENCIONES.md`: código en inglés, comentarios didácticos en español con la etiqueta `CONCEPTO:`, y explicaciones en la conversación antes y después de cada paso relevante.
- Dominio primero y con tests; adaptadores después.
- **Ve tomando nota del porqué de cada decisión**: es la materia prima del documento `explanation` del cierre.
- No hagas commits intermedios de la tarea salvo que el usuario los pida; `/close-task` hace el commit final.
- Si aparece algo pequeño que corregir dentro del alcance, arréglalo en la misma rama. Si es grande o necesita una decisión de negocio, regístralo como documento (`domain_issue`, `tech_debt`…) o como tarea nueva con `/create-task`.
- No amplíes el alcance más allá de los criterios de aceptación.

## Qué no hacer

- No crear ficheros de tareas en el repositorio.
- No cerrar la tarea aquí: eso es `/close-task`.
