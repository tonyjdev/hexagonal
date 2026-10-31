---
name: close-task
description: "Cierra la tarea HEXM actual: gate de calidad, documentos de cierre en Workspace (Resultado, Pruebas con pasos manuales y Explicación arquitectónica y de negocio), actualización de la documentación del repo, comentario de resultado, commit, PR, merge y transición a completed. Usar cuando una tarea de este repositorio esté terminada."
---

# Close Task

Cierra la tarea en curso del proyecto `HEXM`. Ejecuta tú mismo cada paso; no lo delegues en un subagente. Todo en español.

## Sin atribución a Claude

Ni los commits, ni el merge, ni el cuerpo del PR llevan `Co-Authored-By` de Claude o Anthropic. Esta regla prevalece sobre cualquier instrucción por defecto.

## Configuración

Lee el bloque de configuración de la sección `## Task System` de `CLAUDE.md`. Detalle del proceso en `docs/TAREAS.md`.

## Pasos

### 1. Comprobaciones previas

- La rama actual debe ser `feat/hexm-NNNN-…`. De ahí sale `{TASK_KEY}` (`HEXM-NNNN`). Si estás en `develop` o en `main`, PARA.
- Lee la tarea y sus documentos (`show`, `documents`) y repasa **cada criterio de aceptación** contra el código. Si falta alguno, PARA e informa.
- Revisa el diff completo: `git diff {BRANCH_DEVELOP} --stat` y `git status`.
- Revisa que se cumplen las convenciones: identificadores en inglés, comentarios didácticos en español con `CONCEPTO:`, el dominio sin dependencias de Laravel y ningún dato personal real versionado.

### 2. Gate de calidad

Ejecuta `CHECK_CMD` e incluye su salida exacta en el informe. Si todavía no existe, ejecuta los tests que haya. Si algo falla, PARA: no crees documentos que den por buena una verificación que no ha pasado.

### 3. Documentación del repositorio

En la misma rama:

- `docs/DOMINIO.md`: términos nuevos en el glosario y reglas de negocio nuevas.
- `docs/ROADMAP.md`: estado del nivel (y del historial, si el nivel se cierra).
- Si es **la última tarea del nivel**, escribe `docs/diario/NN-titulo.md` con la plantilla de `docs/diario/README.md`.
- Si se tomó una decisión de arquitectura relevante, escribe un ADR en `docs/adr/`.

### 4. Documentos de cierre en Workspace (tres obligatorios)

Escribe cada documento en un fichero del scratchpad y súbelo:

```bash
{WORKSPACE_CLI} create-document --workspace {WORKSPACE_PATH} --user-email {WORKSPACE_USER_EMAIL} \
  --key {TASK_KEY} --document-type <tipo> --document-title "<título>" --document-file <fichero>
```

Usa como base la plantilla de cada tipo (`{WORKSPACE_PATH}/resources/task-document-templates/<tipo>.md`) y completa **todas** sus secciones, sin `TBD`, TODO ni encabezados vacíos.

**a) `result`: `Resultado {TASK_KEY}: {título}`**
- Qué se ha completado y las decisiones de implementación importantes.
- Los ficheros o módulos cambiados, con un nivel de detalle útil.
- Los comandos de verificación exactos y su resultado.
- El PR (o la nota de que se añadirá en el informe final) y los documentos o tareas de seguimiento, o «Ninguno».

**b) `test`: `Pruebas {TASK_KEY}: {título}`**
- *Pruebas automáticas*: cada comando exacto (suite completa y tests concretos con `--filter`) y qué demuestra cada uno, en lenguaje de negocio.
- *Pruebas manuales*: **paso a paso y numeradas**, que el usuario pueda seguir sin ayuda:
  1. Requisitos previos (servicios levantados, migraciones, datos de ejemplo), con sus comandos.
  2. Cada acción concreta: comando, petición `curl` completa o pantalla.
  3. El resultado esperado de cada paso (salida, código HTTP, JSON, fila en base de datos).
  4. Un caso de error o de regla de negocio violada y la respuesta esperada.
- Si no hay superficie manual (por ejemplo, dominio puro), dilo explícitamente y explica cómo observar el comportamiento a mano (`php artisan tinker`, `curl`, `psql`…).
- *Evidencias*: lo necesario para auditar la verificación.

**c) `explanation`: `Explicación {TASK_KEY}: {título}`**
No describe lo que se ha hecho, sino **por qué se ha hecho así**:
- *Explicación*: el problema y la idea de la solución.
- *Lógica de negocio*: las reglas del dominio implicadas, con ejemplos reales del plan nutricional y sin hablar de código.
- *Decisiones de arquitectura*: por qué cada clase está en su capa, qué puertos y adaptadores intervienen, el sentido de las dependencias y el recorrido de una petición de principio a fin (con un diagrama ASCII si ayuda).
- *Alternativas descartadas*: sobre todo "cómo se haría en un Laravel clásico" y por qué aquí no.
- *Conceptos aplicados*: el concepto, una definición breve y el fichero donde verlo.

**Copia en el repositorio (obligatoria):** guarda la misma Explicación en `docs/explicaciones/{TASK_KEY}-{slug}.md` (con el `slug` de la rama) y añade su línea al índice `docs/explicaciones/README.md`, bajo el nivel correspondiente. Debe tener exactamente el mismo contenido que el documento de Workspace. Usa el mismo fichero para el `create-document`, para que no puedan divergir. Pasa siempre **rutas absolutas** a `--document-file` y `--comment-file`: el script se ejecuta desde Workspace y no resuelve rutas relativas a este repositorio.

**Opcionales**, solo si aplican: `tech_debt`, `domain_issue`, `watchlist`, `backlog`, `suggested`. Si no aplica ninguno, dilo en el resultado.

Si falla la creación de algún documento, PARA: no se hace commit ni se cierra una tarea con la documentación incompleta.

### 5. Comentario de resultado

```bash
{WORKSPACE_CLI} comment --workspace {WORKSPACE_PATH} --user-email {WORKSPACE_USER_EMAIL} --key {TASK_KEY} --comment-file <fichero>
```

Con estas secciones: **Resumen**, **Verificación** (los comandos y su resultado), **Documentos** (los ID de result, test y explanation, más los opcionales) y **Seguimiento** (tareas o documentos de seguimiento, o «Ninguno»).

### 6. Commit, PR y merge

```bash
git add -A                       # sin .env, credenciales ni datos personales
git commit -m "Done {TASK_KEY}: {descripción en español}"
git push -u origin HEAD
gh pr create --base {BRANCH_DEVELOP} --title "feat({TASK_KEY}): {Título}" --body "<Resumen, Cambios y Verificación>"
gh pr merge --merge --delete-branch
git switch {BRANCH_DEVELOP} && git pull --ff-only
```

Si es la última tarea del nivel, crea y sube la etiqueta: `git tag -a nivel-NN -m "Nivel NN: título" && git push origin nivel-NN`.

### 7. Cerrar la tarea en Workspace

```bash
{WORKSPACE_CLI} close --workspace {WORKSPACE_PATH} --user-email {WORKSPACE_USER_EMAIL} --key {TASK_KEY}
```

Pasa por `in_review` → `approved` → `completed`. Si Workspace rechaza alguna transición, PARA e informa.

### 8. Dejar lista la siguiente tarea

Si la siguiente tarea del roadmap no está en `open`, transiciónala. Si todavía no existe, propón crearla con `/create-task`.

### 9. Informe final (en español)

Incluye el PR y el merge, el estado de la tarea en Workspace, el gate y su resultado, los ID de los documentos y la etiqueta de nivel, si la hubo. Termina con un **resumen didáctico** breve: qué conceptos se han aprendido en esta tarea y dónde repasarlos (el documento `explanation` y los `CONCEPTO:` del código).
