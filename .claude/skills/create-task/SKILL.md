---
name: create-task
description: "Crea una tarea HEXM en Workspace (la app local de gestión de tareas) con descripción, nivel del roadmap y criterios de aceptación. Usar cuando el usuario pida crear, dar de alta o planificar tareas de este proyecto."
---

# Create Task

Da de alta tareas del proyecto `HEXM` en Workspace. Todo en español.

## Configuración

Lee el bloque de configuración de la sección `## Task System` de `CLAUDE.md` (`WORKSPACE_PATH`, `WORKSPACE_PROJECT_KEY`, `WORKSPACE_USER_EMAIL`, `WORKSPACE_CLI`). Detalle del proceso en `docs/TAREAS.md`.

## Pasos

1. Si la tarea sale del roadmap, lee `docs/ROADMAP.md` para situarla en su nivel. Si es trabajo nuevo, comprueba antes que no exista ya:

   ```bash
   {WORKSPACE_CLI} list --workspace {WORKSPACE_PATH} --project-key HEXM --status suggested,backlog,open,in_progress --limit 50
   ```

2. Escribe la descripción en un fichero del scratchpad con esta estructura:

   ```markdown
   **Nivel:** N. Nombre del nivel (docs/ROADMAP.md)

   ## Objetivo
   Qué se quiere conseguir y por qué, en dos o tres frases.

   ## Alcance
   - Qué entra.
   - Qué queda fuera (y en qué tarea o nivel irá).

   ## Criterios de aceptación
   - [ ] Comprobable 1
   - [ ] Comprobable 2

   ## Conceptos que se aprenden
   - Concepto: una línea.
   ```

3. Crea la tarea. El script asigna la clave siguiente (`HEXM-NNNN`) y la imprime como `TASK_KEY=…`:

   ```bash
   {WORKSPACE_CLI} create --workspace {WORKSPACE_PATH} --user-email {WORKSPACE_USER_EMAIL} \
     --project-key HEXM --title "Título corto en español" --description-file <fichero> \
     --type feature|chore|bug|research|task --priority low|medium|high --status backlog|open
   ```

   - Estado `open` solo para la siguiente tarea a abordar; el resto, `backlog`.
   - Títulos cortos: de ellos sale el *slug* de la rama.

4. Informa de la clave, el título y el estado de cada tarea creada.

## Qué no hacer

- No crear ficheros de tareas en el repositorio.
- No empezar a implementar: eso es `/start-task`.
