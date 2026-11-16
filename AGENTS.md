# AGENTS.md

Instrucciones permanentes para trabajar en este repositorio. Se aplican en **todas** las sesiones.

El repositorio usa el **sistema de tareas compartido** (`~/.agents/workspace-kit`, configurado en
`.claude/task-system.conf`): las reglas comunes (ramas, worktrees, cierre de tareas, documentos de
cierre, diagramas, despliegues) están en el kit, y este fichero solo añade **lo propio de este
proyecto**. Donde los dos difieran, gana este fichero, salvo en los estándares del kit.

## Qué es este proyecto

Un proyecto **de aprendizaje**: una aplicación real (gestión de los planes de la nutricionista + recetario) que sirve como excusa para aprender:

1. **Arquitectura hexagonal** (y DDD táctico) con **PHP 8.5 / Laravel 13 / PostgreSQL**, el stack principal del usuario.
2. **Python + FastAPI** en servicios pequeños que actúan como adaptadores externos.
3. **IA aplicada** (LLM, structured outputs, embeddings, pgvector, RAG, agentes, modelos locales), siguiendo el plan de aprendizaje personal del usuario.

El objetivo no es solo que funcione, sino **que el usuario entienda cada paso**.

## Documentación de referencia (leer antes de trabajar)

- @docs/CONVENCIONES.md — idioma, nomenclatura, comentarios didácticos y forma de trabajar.
- @docs/ROADMAP.md — niveles del proyecto y en cuál estamos.
- @docs/DOMINIO.md — lenguaje ubicuo (glosario español → inglés) y modelo del dominio.
- `docs/TAREAS.md` — cómo se aplica aquí el sistema de tareas (ramas, worktrees, gate, cierre).

## Stack y estructura

- `app-laravel/`: núcleo hexagonal (PHP 8.5, Laravel 13). El código propio vive en `app-laravel/src/` (contextos `Recipes`, `MealPlanning` y `Shared`), no en `app/`. Reglas de capas en `app-laravel/src/README.md`.
- `services/importer/`: servicio Python con FastAPI (uv, ruff, mypy, pytest). Ver su README.
- PostgreSQL 18 nativo con pgvector, preparado por `scripts/db-setup.sh`. **Sin Docker en local** (ADR 0020).
- Gate de calidad (`CLOSE_CHECK_CMD`): `(cd app-laravel && composer ci:check) && (cd services/importer && uv run poe check)`.

## Reglas resumidas (el detalle está en docs/CONVENCIONES.md)

- **Inglés solo para el código** (clases, métodos, variables, tablas, rutas, eventos: `RecipeDeleted`, no `RecetaEliminada`). **Todo lo demás en español**: comentarios, documentación, commits, PR, documentos de Workspace y mensajes de las conversaciones.
- **Código muy comentado y didáctico**: explicar *qué* se hace y sobre todo *por qué*, nombrando el concepto de arquitectura o de la tecnología que se está aplicando (etiqueta `CONCEPTO:`).
- **Explicaciones también en la conversación**: antes de cada paso relevante, qué se va a hacer y por qué; al terminarlo, qué se ha hecho, qué concepto se ha aplicado y qué viene después.
- **Avanzar por niveles** del roadmap, en pasos pequeños, con tests. Dominio primero, adaptadores después. No adelantar complejidad (IA, Python) antes de que toque.
- **Datos personales fuera del repo**: los PDF/Excel reales del usuario no se versionan; los tests usan datos de ejemplo de `tests/fixtures/`.
- **Commits**: *conventional commits* con la descripción en español (`feat(HEXM-0029): lee el Excel de la nutricionista`).

## Tareas: lo propio de HEXM

Las tareas viven en Workspace (proyecto `HEXM`), nunca en el repositorio. Ciclo del kit:
`/init-task HEXM-NNNN` → `/start-task` → `/close-task`, cada tarea en su worktree `.worktrees/hexm-nnnn`.
Además de lo que hace el kit:

### Al empezar (`/start-task`)

Antes de escribir código, explica al usuario el objetivo de la tarea y su lugar en el roadmap, el plan en pasos pequeños y los conceptos nuevos que van a aparecer (de arquitectura, de Laravel, de Python o de IA). Ve tomando nota del porqué de cada decisión: es la materia prima de la Explicación.

### Al crear tareas (`/create-task`)

La descripción sigue esta estructura:

```markdown
**Nivel:** N. Nombre del nivel (docs/ROADMAP.md)   ← o «transversal»

## Objetivo
## Alcance            (qué entra y qué queda fuera, con la tarea o el nivel donde irá)
## Criterios de aceptación   (- [ ] comprobables)
## Conceptos que se aprenden
```

Títulos cortos en español: de ellos sale el *slug* de la rama.

### Al cerrar (`/close-task`)

1. **Puntos de revisión del diff**: identificadores en inglés, comentarios didácticos en español con `CONCEPTO:`, el núcleo sin dependencias de Laravel (lo comprueban los tests de `app-laravel/tests/Architecture/`) y ningún dato personal real versionado.
2. **Documentación viva**, en la misma rama y antes del commit del trabajo:
   - `docs/DOMINIO.md`: términos nuevos del glosario y reglas de negocio nuevas.
   - `docs/ROADMAP.md`: estado del nivel y una línea en el historial por tarea.
   - Si es **la última tarea del nivel**: `docs/diario/NN-titulo.md` (plantilla en `docs/diario/README.md`) y, después del merge, la etiqueta `nivel-NN` (`git tag -a nivel-NN -m "Nivel NN: título"` en `develop` y `git push origin nivel-NN`, preguntando antes).
   - Una decisión de arquitectura relevante → ADR en `docs/adr/`.
3. **Explicación en el repositorio (obligatoria)**: el documento `explanation` de Workspace se guarda **también** en `docs/explicaciones/HEXM-NNNN-slug.md` (el *slug* de la rama), con idéntico contenido, y se añade su línea al índice `docs/explicaciones/README.md` bajo su nivel. Usa el mismo fichero para el `create-document`, para que no puedan divergir. Secciones: Explicación · Lógica de negocio · Decisiones de arquitectura (por qué cada pieza en su capa, puertos y adaptadores, sentido de las dependencias, recorrido de una petición) · Alternativas descartadas (sobre todo «cómo se haría en un Laravel clásico» y por qué aquí no) · Conceptos aplicados (concepto, definición breve y fichero) · Diagramas. Como se versiona en el commit de los diagramas, la línea de Diagramas cita sus fuentes con rutas relativas (`../diagramas/HEXM-NNNN/<slug>.<tipo>.json`) y cómo verlos (`~/.agents/workspace-kit/bin/diagrams.sh HEXM-NNNN`).
4. **Pruebas manuales paso a paso** en el documento `test`: requisitos previos con sus comandos, cada acción numerada (comando, `curl` completo o pantalla), el resultado esperado y al menos un caso de regla de negocio violada. Si no hay superficie manual, dilo y explica cómo observarlo (`php artisan tinker`, `curl`, `psql`…).
5. **Diagramas Archify**: convenciones del proyecto (leyenda con el vocabulario de la hexagonal, tipos según lo que se explica e índice) en `docs/diagramas/README.md`. Al añadir un diagrama, se añade también a ese índice.
6. **Informe final**: termina con un **resumen didáctico** breve: qué conceptos se han aprendido y dónde repasarlos (la Explicación, los diagramas y los `CONCEPTO:` del código).

### Worktrees y base de datos

`/init-task` prepara cada worktree con `scripts/worktree-setup.sh` (`SETUP_CMD`): copia `app-laravel/.env` del checkout principal, `composer install` y `uv sync`. Todos los worktrees usan las mismas bases de datos locales (`hexagonal` y `hexagonal_test`): no ejecutes el gate de dos worktrees a la vez, y las migraciones de una tarea se aplican a la base de datos de desarrollo solo después del merge (`POST_MERGE_CMD`, en el checkout principal).
