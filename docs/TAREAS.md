# Gestión de tareas

Las tareas de este proyecto se gestionan en **Workspace** (la aplicación Laravel local en `/home/tonyjdev/Projects/workspace`), en el proyecto con clave **`HEXM`** («Menú Hexagonal»).

Desde HEXM-0035 el repositorio usa el **sistema de tareas compartido** (`~/.agents/workspace-kit`), el mismo que el resto de proyectos del usuario: las skills de tareas son globales y este repositorio solo pone su configuración (`.claude/task-system.conf`) y lo que le es propio (`AGENTS.md`). Este documento explica cómo se aplica aquí; las reglas comunes completas están en el `README.md` y el `AGENTS.shared.md` del kit.

> Las tareas **no** viven en este repositorio: no se crean carpetas `tasks/` ni `ai-tasks/`. El estado y los documentos de cada tarea están en la base de datos de Workspace.

---

## 1. Tareas y niveles

- El [roadmap](ROADMAP.md) está organizado en **niveles**. Cada nivel se divide en **una o varias tareas** `HEXM-NNNN`; las que no pertenecen a ninguno son **transversales**.
- La descripción de cada tarea indica a qué nivel pertenece y sus **criterios de aceptación** (estructura en `AGENTS.md`).
- Cuando se cierra la última tarea de un nivel, también se cierra el nivel: se actualiza el roadmap, se escribe la entrada del [diario](diario/) y se crea la etiqueta git `nivel-NN`.

## 2. Ciclo de vida

Workspace define los estados: `suggested` → `backlog` → `open` → `ready` → `in_progress` → `in_review` → `approved` → `completed` (y además `blocked`, `cancelled` y `discarded`). `ready` («Preparada») significa que la tarea ya tiene su worktree y su rama, pero no se ha empezado.

| Fase | Comando | Qué hace |
|---|---|---|
| Crear | `/create-task` | Da de alta una tarea `HEXM` en Workspace con su descripción, criterios de aceptación y etiquetas. |
| Revisar | `/start-review` … `/close-review` | Contrasta tareas todavía no empezadas con el código, resuelve dudas, las parte si son grandes y las deja `open`. No escribe ficheros. |
| Preparar | `/init-task HEXM-NNNN` | Crea el worktree `.worktrees/hexm-nnnn` y la rama `<tipo>/hexm-nnnn-slug` desde `origin/develop`, prepara el entorno (`scripts/worktree-setup.sh`), pasa la tarea a `ready` y lleva la sesión dentro. **La rama es la reserva de la tarea.** |
| Empezar | `/start-task` | Lee la tarea y sus documentos y la pasa a `in_progress`. No toca git. |
| Cerrar | `/close-task` | Gate de calidad, commit del trabajo, **diagramas Archify**, PR a `develop`, documentos de cierre, comentario de resultado, merge, tarea `completed` y borrado del worktree y de la rama. |
| Desplegar | `/deploy-staging`, `/deploy-production` | Promociones `develop` → `staging` → `main`, solo cuando se pidan (sección 4). |

**Regla fija:** una tarea = un worktree = una rama = un PR. No se mezclan dos tareas en la misma rama.

## 3. Documentos de cierre de cada tarea

Toda tarea cerrada deja **tres documentos obligatorios** en Workspace, y un cuarto si tiene diagramas:

| Tipo | Título | Contenido |
|---|---|---|
| `result` | `Resultado HEXM-NNNN: …` | Qué se ha hecho: comportamiento, ficheros principales, comandos de verificación con su salida, el PR y las tareas de seguimiento (por clave). |
| `test` | `Pruebas HEXM-NNNN: …` | **Cómo probarlo.** Tests automáticos (comandos exactos y qué demuestra cada suite) y **pruebas manuales paso a paso**: requisitos previos, cada acción numerada (comando, petición HTTP o pantalla) y el resultado esperado. Si no hay superficie manual, se dice explícitamente y se explica cómo observar el cambio de otra forma (por ejemplo, desde `php artisan tinker`). |
| `explanation` | `Explicación HEXM-NNNN: …` | **No qué se ha hecho, sino por qué así.** Secciones abajo. **También se guarda en el repositorio**, en `docs/explicaciones/` (ver más abajo). |
| `diagrams` | `Diagramas HEXM-NNNN: …` | Solo si la tarea tiene diagramas: qué muestra cada uno, dónde está su fuente (con el enlace a GitHub en el commit de los diagramas) y cómo verlo. |

El trabajo pendiente que aparece al cerrar **no es un documento**: se crea en ese momento como tarea (`/create-task`, en `backlog` o `suggested`, citando la tarea de origen; de tipo `tech_debt` si paga deuda técnica) y el `result` la cita por su clave. Los documentos opcionales `tech_debt`, `domain_issue` y `watchlist` quedan para el contexto que no es trabajo pendiente.

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
6. **Diagramas**: los diagramas Archify de la tarea, con el enlace a su fuente, el tipo y qué pregunta responde cada uno, o el motivo por el que la tarea no tiene ninguno.

### Diagramas de cada tarea

Al cerrar una tarea, **siempre que tenga algo que dibujar**, se generan con [Archify](https://github.com/tt-a1i/archify) entre uno y tres diagramas que acompañan a la Explicación: el flujo de datos de un caso de uso (`sequence`), una regla de negocio con sus ramas de error (`workflow`), las capas y los puertos (`architecture`), un agregado (`class`), las tablas (`erd`)…

- Están **respaldados por el código**: cada elemento cita sus ficheros y líneas en el commit del trabajo de la tarea, y Archify comprueba que existen. Por eso `/close-task` hace primero el commit del trabajo y después genera los diagramas, y el merge se hace con `--merge` (no *squash*) para que ese commit siga existiendo en `develop`.
- **Solo se versiona la fuente** (`docs/diagramas/HEXM-NNNN/<slug>.<tipo>.json`). El HTML interactivo se genera fuera del proyecto, en `~/.cache/workspace-kit/diagramas/hexagonal/`, con `cd app-laravel && composer docs:diagrams`.
- Convenciones, guía de tipos e índice: [`docs/diagramas/README.md`](diagramas/README.md).

## 4. Ramas, worktrees, commits y PR

Estrategia de ramas: [ADR 0001](adr/0001-estrategia-de-ramas.md).

```
<tipo>/hexm-nnnn-slug ──PR──► develop ──/deploy-staging──► staging ──/deploy-production──► main
  (en .worktrees/hexm-nnnn)  (integración)                (preproducción)                (producción)
```

- **Rama de tarea**: `<tipo>/hexm-nnnn-slug`, con el prefijo según el tipo de la tarea (`feat`, `fix`, `chore`, `research`, `docs`), creada por `/init-task` desde `origin/develop`.
- **Worktrees**: cada tarea se trabaja en su propia carpeta, `.worktrees/hexm-nnnn` (ignorada por git), con su rama sacada. El **checkout principal** (la raíz del repositorio) se queda siempre en `develop`, sirviendo la aplicación; nunca se trabaja una tarea en él.
  - *Por qué*: se pueden tener varias tareas abiertas (o una tarea y una revisión) sin cambiar de rama ni guardar cambios a medias, y lo que está en `develop` sigue funcionando mientras tanto.
  - Cada worktree se prepara con `scripts/worktree-setup.sh` (copia `app-laravel/.env`, `composer install` y `uv sync`). Todos usan las mismas bases de datos locales, así que no se ejecutan dos gates a la vez.
  - Las ramas y los worktrees se crean y se borran **solo** con `task-worktree.sh` (lo hacen las skills), nunca a mano.
- **Commits**: *conventional commits* con la descripción en español (`feat(HEXM-0029): …`). Ni los commits ni el PR llevan atribución `Co-Authored-By`.
- **PR** contra **`develop`** en GitHub (`tonyjdev/hexagonal`), con resumen, cambios y verificación; se fusiona con `--merge`.
- **`staging` y `main` solo reciben promociones**, nunca trabajo directo: `/deploy-staging` (`develop` → `staging`, con el gate completo y una etiqueta de versión) y `/deploy-production` (`staging` → `main`). Se ejecutan solo cuando se piden, desde el checkout principal. Mientras no haya despliegue, `staging` y `main` no despliegan en ningún entorno (ADR 0001).

## 5. Gate de calidad

Antes de cerrar una tarea tiene que pasar el gate (`CLOSE_CHECK_CMD` en `.claude/task-system.conf`; también es el `RELEASE_CHECK_CMD` de `/deploy-staging`):

```bash
(cd app-laravel && composer ci:check) && (cd services/importer && uv run poe check)
```

Son dos gates, uno por aplicación, y los dos tienen que pasar.

**Laravel** (`composer ci:check`; los tests usan el PostgreSQL local, ADR 0002 y 0020) ejecuta, por orden y parando en el primer fallo (de lo más rápido a lo más lento):

| Paso | Comando suelto | Qué comprueba |
|---|---|---|
| 1. Formato | `composer format:check` | Pint, con el preset de Laravel. Para corregirlo: `composer lint`. |
| 2. Análisis estático | `composer types:check` | PHPStan + Larastan al nivel `max`. |
| 3. Tests | `composer test` | Pest, contra la base de datos `hexagonal_test`. |

**Servicio `importer`** (`uv run poe check`, desde HEXM-0028, ADR 0021): ruff format, ruff check, mypy estricto y pytest, con el mismo orden y la misma regla. El detalle, en [`services/importer/README.md`](../services/importer/README.md).

**Prueba de punta a punta** (HEXM-0033, [ADR 0026](adr/0026-api-de-la-importacion-y-prueba-de-punta-a-punta.md)): la importación de un plan por la API de Laravel con el servicio `importer` real y sus Excel ficticios. Dentro de `composer test` se **salta** si el servicio no responde en `/health`, así que el gate no depende de él. Antes de cerrar una tarea que toque la importación, el puerto `PlanReader` o el servicio, lánzala expresamente; así **falla** si el servicio no está levantado:

```bash
(cd services/importer && uv run poe serve)   # en otra terminal
(cd app-laravel && composer test:e2e)
```

La salida exacta del gate se copia siempre en el documento de resultado de la tarea.

## 6. Configuración y herramientas

`.claude/task-system.conf` (claves y valores por defecto en `~/.agents/workspace-kit/templates/task-system.conf`):

| Clave | Valor | Para qué |
|---|---|---|
| `WORKSPACE_PROJECT_KEY` | `HEXM` | Proyecto de Workspace. |
| `SETUP_CMD` | `bash scripts/worktree-setup.sh` | Prepara cada worktree nuevo. |
| `CLOSE_CHECK_CMD` | los dos gates | Puerta de `/close-task`. |
| `POST_MERGE_CMD` | `composer install` + `migrate` y `uv sync` | Pone al día el checkout principal (y la base de datos de desarrollo) después de cada merge. |
| `CHANGELOG` | `none` | Sin changelog: el historial del roadmap y el diario hacen ese papel. Se puede pasar a `kit` con el primer despliegue. |
| `CI_WAIT` | `none` | Todavía no hay CI en GitHub. |
| `RELEASE_CHECK_CMD` | los dos gates | Comprobación completa antes de sellar una versión en `staging`. |

Comandos útiles:

```bash
K=~/.agents/workspace-kit/bin
W=/home/tonyjdev/Projects/workspace

$K/task-worktree.sh config     # la configuración resuelta
$K/task-worktree.sh doctor     # comprueba los estándares del kit
$K/manage_workspace_task.sh list      --workspace $W --project-key HEXM --status backlog,open,in_progress
$K/manage_workspace_task.sh show      --workspace $W --key HEXM-0001
$K/manage_workspace_task.sh documents --workspace $W --key HEXM-0001
```

La interfaz web de Workspace muestra las mismas tareas y documentos.
