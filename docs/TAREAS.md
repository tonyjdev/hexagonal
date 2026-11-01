# Gestión de tareas

Las tareas de este proyecto se gestionan en **Workspace** (la aplicación Laravel local en `/home/tonyjdev/Projects/workspace`), en el proyecto con clave **`HEXM`** («Menú Hexagonal»). Es el mismo sistema que usan otros proyectos como `controlpanel` (clave `CAPI`), simplificado para un proyecto de aprendizaje con un solo agente.

> Las tareas **no** viven en este repositorio: no se crean carpetas `tasks/` ni `ai-tasks/`. El estado y los documentos de cada tarea están en la base de datos de Workspace.

---

## 1. Tareas y niveles

- El [roadmap](ROADMAP.md) está organizado en **niveles**. Cada nivel se divide en **una o varias tareas** `HEXM-NNNN`.
- La descripción de cada tarea indica a qué nivel pertenece y sus **criterios de aceptación**.
- Cuando se cierra la última tarea de un nivel, también se cierra el nivel: se actualiza el roadmap, se escribe la entrada del [diario](diario/) y se crea la etiqueta git `nivel-NN`.

## 2. Ciclo de vida

Workspace define los estados: `suggested` → `backlog` → `open` → `in_progress` → `in_review` → `approved` → `completed` (y además `blocked`).

| Fase | Comando | Qué hace |
|---|---|---|
| Crear | `/create-task` | Da de alta una tarea `HEXM` en Workspace con su descripción y criterios de aceptación. |
| Empezar | `/start-task [HEXM-NNNN]` | Lee la tarea y sus documentos, crea la rama `feat/hexm-NNNN-slug` desde `develop` y pasa la tarea a `in_progress`. |
| Cerrar | `/close-task` | Gate de calidad, commit del trabajo, **diagramas Archify**, documentos de cierre (**Resultado, Pruebas y Explicación**), comentario de resultado, commit de documentación, PR, merge y cierre de la tarea. |

**Regla fija:** una tarea = una rama = un PR. No se mezclan dos tareas en la misma rama.

## 3. Documentos de cierre de cada tarea

Toda tarea cerrada deja **tres documentos obligatorios** en Workspace:

| Tipo | Título | Contenido |
|---|---|---|
| `result` | `Resultado HEXM-NNNN: …` | Qué se ha hecho: comportamiento, ficheros principales, comandos de verificación con su salida y tareas o documentos de seguimiento. |
| `test` | `Pruebas HEXM-NNNN: …` | **Cómo probarlo.** Tests automáticos (comandos exactos y qué demuestra cada suite) y **pruebas manuales paso a paso**: requisitos previos, cada acción numerada (comando, petición HTTP o pantalla) y el resultado esperado. Si no hay superficie manual, se dice explícitamente y se explica cómo observar el cambio de otra forma (por ejemplo, desde `php artisan tinker`). |
| `explanation` | `Explicación HEXM-NNNN: …` | **No qué se ha hecho, sino por qué así.** Se basa en la plantilla de Workspace (secciones abajo). **También se guarda en el repositorio**, en `docs/explicaciones/` (ver más abajo). |

### Copia de las explicaciones en el repositorio

Cada Explicación se guarda **también** como fichero en `docs/explicaciones/HEXM-NNNN-slug.md`, con el mismo contenido que el documento de Workspace, y se versiona en el mismo PR de la tarea. Así:

- el material de estudio queda junto al código que explica y se puede consultar desde el editor o en GitHub;
- se puede leer la evolución del proyecto en orden, tarea a tarea;
- el repositorio sirve como portfolio sin depender de Workspace.

El índice `docs/explicaciones/README.md` lista todas las explicaciones por nivel. **Workspace y el repositorio deben coincidir**: si se corrige una explicación, se actualizan los dos.

### La «Explicación» en detalle

Plantilla: `workspace/resources/task-document-templates/explanation.md`.

1. **Explicación**: el problema que resuelve la tarea y la idea general de la solución, en pocas líneas.
2. **Lógica de negocio**: las reglas del dominio que motivan la tarea, con ejemplos reales del plan nutricional y sin hablar todavía de código. Sirve de puente entre el negocio y la parte técnica.
3. **Decisiones de arquitectura**: por qué cada pieza está en su capa (dominio, aplicación o infraestructura), qué puertos y adaptadores intervienen, hacia dónde van las dependencias y cómo viaja una petición de principio a fin. Un diagrama ASCII si ayuda.
4. **Alternativas descartadas**: lo que se podría haber hecho de otra forma (por ejemplo, "la lógica en el modelo Eloquent, como en un Laravel clásico") y por qué no se eligió.
5. **Conceptos aplicados**: cada concepto (value object, puerto, adaptador…), con una definición breve y el fichero donde verlo.
6. **Diagramas** (sección propia del proyecto, añadida a la plantilla): los diagramas Archify de la tarea, con su enlace, el tipo y qué pregunta responde cada uno, o el motivo por el que la tarea no tiene ninguno. Ver abajo.

### Diagramas de cada tarea

Al cerrar una tarea, **siempre que tenga algo que dibujar**, se generan con [Archify](https://github.com/tt-a1i/archify) entre uno y tres diagramas que acompañan a la Explicación: el flujo de datos de un caso de uso (`sequence`), una regla de negocio con sus ramas de error (`workflow`), las capas y los puertos (`architecture`), un agregado (`class`), las tablas (`erd`)…

- Están **respaldados por el código**: cada elemento cita sus ficheros y líneas en el commit "Done" de la tarea, y Archify comprueba que existen. Por eso `/close-task` hace primero el commit del trabajo y después genera los diagramas.
- **Solo se versiona la fuente** (`docs/diagramas/HEXM-NNNN/<slug>.<tipo>.json`). El HTML interactivo se regenera en local con `cd app-laravel && composer docs:diagrams`.
- Convenciones, guía de tipos e índice: [`docs/diagramas/README.md`](diagramas/README.md).

### Documentos opcionales

Solo cuando aplican: `tech_debt` (deuda técnica), `domain_issue` (una regla de negocio sin decidir), `watchlist` (riesgos a vigilar), `backlog` y `suggested` (trabajo futuro).

## 4. Ramas, commits y PR

Estrategia de ramas: [ADR 0001](adr/0001-estrategia-de-ramas.md).

```
feat/hexm-NNNN-slug ──PR──► develop ──PR──► staging ──PR──► main
                          (integración)  (preproducción)  (producción)
                                          └─ se creará con el primer despliegue
```

- Rama de tarea: `feat/hexm-NNNN-slug`, creada desde `develop` actualizado. Sin worktrees: aquí trabaja un solo agente y así es más fácil de seguir.
- Commits en español. El commit final de la tarea es `Done HEXM-NNNN: descripción`.
- PR contra **`develop`** en GitHub (`tonyjdev/hexagonal`), con resumen, cambios y verificación. Se fusiona con `gh pr merge --merge --delete-branch`.
- `main` (y más adelante `staging`) **solo recibe promociones** por PR desde la rama anterior, nunca trabajo directo. Al cerrar un nivel se puede promocionar `develop` → `main`.
- Ni los commits ni el PR llevan atribución `Co-Authored-By` de Claude.

## 5. Gate de calidad

Antes de cerrar una tarea tiene que pasar el gate (`CHECK_CMD` en `CLAUDE.md`):

```bash
docker compose up -d                  # los tests usan PostgreSQL (ADR 0002)
cd app-laravel && composer ci:check
```

`ci:check` ejecuta, por orden y parando en el primer fallo (de lo más rápido a lo más lento):

| Paso | Comando suelto | Qué comprueba |
|---|---|---|
| 1. Formato | `composer format:check` | Pint, con el preset de Laravel. Para corregirlo: `composer lint`. |
| 2. Análisis estático | `composer types:check` | PHPStan + Larastan al nivel `max`. |
| 3. Tests | `composer test` | Pest, contra la base de datos `hexagonal_test`. |

La salida exacta del gate se copia siempre en el documento de resultado de la tarea.

## 6. Herramienta

Todo acceso a Workspace pasa por el script:

```bash
/home/tonyjdev/.agents/skills/workspace-task-creator/scripts/manage_workspace_task.sh <comando> --workspace /home/tonyjdev/Projects/workspace …
```

Comandos útiles (`help` muestra todos):

```bash
W=/home/tonyjdev/Projects/workspace
T=/home/tonyjdev/.agents/skills/workspace-task-creator/scripts/manage_workspace_task.sh

$T list      --workspace $W --project-key HEXM --status backlog,open,in_progress
$T show      --workspace $W --key HEXM-0001
$T documents --workspace $W --key HEXM-0001
```

La interfaz web de Workspace muestra las mismas tareas y documentos.
