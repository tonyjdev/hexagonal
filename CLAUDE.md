# CLAUDE.md

Instrucciones permanentes para trabajar en este repositorio. Se aplican en **todas** las sesiones.

## Qué es este proyecto

Un proyecto **de aprendizaje**: una aplicación real (gestión de los planes de la nutricionista + recetario) que sirve como excusa para aprender:

1. **Arquitectura hexagonal** (y DDD táctico) con **PHP 8.5 / Laravel 12 / PostgreSQL**, el stack principal del usuario.
2. **Python + FastAPI** en servicios pequeños que actúan como adaptadores externos.
3. **IA aplicada** (LLM, structured outputs, embeddings, pgvector, RAG, agentes, modelos locales), siguiendo el plan de aprendizaje personal del usuario.

El objetivo no es solo que funcione, sino **que el usuario entienda cada paso**.

## Documentación de referencia (leer antes de trabajar)

- @docs/CONVENCIONES.md — idioma, nomenclatura, comentarios didácticos y forma de trabajar.
- @docs/ROADMAP.md — niveles del proyecto y en cuál estamos.
- @docs/DOMINIO.md — lenguaje ubicuo (glosario español → inglés) y modelo del dominio.

## Reglas resumidas (el detalle está en docs/CONVENCIONES.md)

- **Inglés solo para el código** (clases, métodos, variables, tablas, rutas, eventos: `RecipeDeleted`, no `RecetaEliminada`). **Todo lo demás en español**: comentarios, documentación, commits, mensajes de las conversaciones.
- **Código muy comentado y didáctico**: explicar *qué* se hace y sobre todo *por qué*, nombrando el concepto de arquitectura o de la tecnología que se está aplicando.
- **Explicaciones también en la conversación**: al terminar cada paso, explicar qué se ha hecho, qué concepto se ha aplicado y qué viene después.
- **Avanzar por niveles** del roadmap, en pasos pequeños, con tests. No adelantar complejidad (IA, Python) antes de que toque.
- **Mantener la documentación viva**: al cerrar un nivel, actualizar `docs/ROADMAP.md`, el glosario de `docs/DOMINIO.md` si aparecen términos nuevos, y escribir la entrada correspondiente en `docs/diario/`.
- **Datos personales fuera del repo**: los PDF/Excel reales del usuario no se versionan; los tests usan datos de ejemplo.

## Task System

Las tareas viven en Workspace (proyecto `HEXM`), nunca en este repositorio: no crear `tasks/` ni `ai-tasks/`. Ciclo: `/create-task` → `/start-task` → `/close-task`. Cada tarea cerrada deja tres documentos obligatorios en Workspace: `result`, `test` (con pruebas manuales paso a paso) y `explanation` (por qué así a nivel de arquitectura, más la lógica de negocio). La `explanation` se guarda **también** en `docs/explicaciones/HEXM-NNNN-slug.md` con idéntico contenido. Detalle en @docs/TAREAS.md.

Configuración que leen los skills de tareas:

```markdown
WORKSPACE_PATH: /home/tonyjdev/Projects/workspace
WORKSPACE_PROJECT_KEY: HEXM
WORKSPACE_USER_EMAIL: test@example.com
WORKSPACE_CLI: /home/tonyjdev/.agents/skills/workspace-task-creator/scripts/manage_workspace_task.sh
BRANCH_MAIN: main
BRANCH_FEATURE_PREFIX: feat/
CHECK_CMD: (ninguno todavía; lo crea el nivel 0)
```
