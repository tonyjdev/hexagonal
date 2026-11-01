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
