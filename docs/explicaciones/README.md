# Explicaciones

Cada tarea `HEXM` cerrada deja aquí su **Explicación**: no lo que se hizo, sino **por qué se hizo así** a nivel de arquitectura, junto con la lógica de negocio que lo motiva. Es una copia del documento `explanation` de Workspace (ver [`TAREAS.md`](../TAREAS.md)).

Fichero: `HEXM-NNNN-slug.md`. Secciones: Explicación · Lógica de negocio · Decisiones de arquitectura · Alternativas descartadas · Conceptos aplicados · Diagramas.

Los diagramas Archify de cada tarea están en [`../diagramas/`](../diagramas/README.md). Las explicaciones anteriores a su integración (HEXM-0005 a 0007) no tienen la sección "Diagramas", porque Workspace y el repositorio deben coincidir; sus diagramas se enlazan aquí, en el índice.

## Índice

### Nivel 0: Esqueleto

- [HEXM-0001: Esqueleto Laravel y PostgreSQL](HEXM-0001-esqueleto-laravel-y-postgresql.md): por qué la base de datos va en Docker y es un detalle de infraestructura.
- [HEXM-0002: Gate de calidad con Pest, PHPStan y Pint](HEXM-0002-gate-de-calidad-con-pest-phpstan-y-pint.md): por qué un gate único, el análisis estático al máximo y los tests contra PostgreSQL.
- [HEXM-0003: Estructura hexagonal y tests de arquitectura](HEXM-0003-estructura-hexagonal-y-tests-de-arquitectura.md): por qué el núcleo vive en `src/`, separado por contextos y capas, y por qué la regla de dependencias es un test.
- [HEXM-0004: Tests de arquitectura con lista blanca](HEXM-0004-tests-de-arquitectura-con-lista-blanca.md): por qué prohibir Laravel no basta y el núcleo solo puede usar lo autorizado.

### Nivel 1: Recetario (dominio)

- [HEXM-0005: Value objects del recetario](HEXM-0005-value-objects-del-recetario.md): por qué cantidades, raciones, pasos e identificadores son tipos propios que no pueden ser inválidos, por qué las fracciones son exactas y por qué el dominio genera la identidad. Diagrama: [de texto a cantidad exacta](../diagramas/HEXM-0005/de-texto-a-cantidad-exacta.workflow.json).
- [HEXM-0006: Entidad Recipe e ingredientes](HEXM-0006-entidad-recipe-e-ingredientes.md): por qué una receta es una entidad y un ingrediente no, qué protege un agregado y por qué no hay setters. Diagrama: [agregado Recipe](../diagramas/HEXM-0006/agregado-recipe.class.json).
- [HEXM-0007: Caso de uso CreateRecipe con repositorio en memoria](HEXM-0007-caso-de-uso-createrecipe-con-repositorio-en-memoria.md): por qué el caso de uso orquesta sin decidir, por qué el repositorio es un puerto del dominio y por qué un fake en memoria en vez de un mock. Diagramas: [CreateRecipe paso a paso](../diagramas/HEXM-0007/create-recipe-paso-a-paso.sequence.json) y [hexágono del recetario](../diagramas/HEXM-0007/hexagono-recetario.architecture.json).

### Nivel 2: Recetario (adaptadores)

- [HEXM-0009: Repositorio Eloquent de recetas](HEXM-0009-repositorio-eloquent-de-recetas.md): por qué el modelo de Eloquent no es la receta, cómo se reconstituye un agregado sin saltarse sus invariantes, por qué guardar es una transacción y por qué los tests de contrato. Diagramas: [tablas del recetario](../diagramas/HEXM-0009/tablas-del-recetario.erd.json) y [guardar y cargar una receta](../diagramas/HEXM-0009/guardar-y-cargar-una-receta.sequence.json).
- [HEXM-0010: Alta de recetas por API](HEXM-0010-alta-de-recetas-por-api.md): por qué el controlador solo traduce, por qué la frontera valida la forma y deja las reglas al dominio, cómo llegan los errores de dominio como 422 desde un único manejador y qué es la raíz de composición. Diagramas: [alta de una receta por HTTP](../diagramas/HEXM-0010/alta-de-receta-por-http.sequence.json) y [forma frente a reglas](../diagramas/HEXM-0010/forma-frente-a-reglas.workflow.json).
- [HEXM-0011: Consulta de recetas por API](HEXM-0011-consulta-de-recetas-por-api.md): por qué una consulta devuelve un DTO de lectura y no la entidad (CQS), por qué "1 ½" lo escribe el dominio, por qué "no encontrado" es un 404 de la capa de aplicación y un id imposible un 422. Diagramas: [consultar una receta](../diagramas/HEXM-0011/consultar-una-receta.sequence.json) y [hexágono del recetario al cerrar el nivel 2](../diagramas/HEXM-0011/hexagono-recetario-nivel-2.architecture.json).

### Transversal

- [HEXM-0008: Diagramas Archify en las tareas](HEXM-0008-diagramas-archify-en-las-tareas.md): por qué los diagramas se generan desde una fuente versionada, respaldados por el código en un commit fijo, y por qué el cierre de una tarea tiene dos commits.
