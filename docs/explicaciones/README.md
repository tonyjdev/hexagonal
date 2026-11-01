# Explicaciones

Cada tarea `HEXM` cerrada deja aquí su **Explicación**: no lo que se hizo, sino **por qué se hizo así** a nivel de arquitectura, junto con la lógica de negocio que lo motiva. Es una copia del documento `explanation` de Workspace (ver [`TAREAS.md`](../TAREAS.md)).

Fichero: `HEXM-NNNN-slug.md`. Secciones: Explicación · Lógica de negocio · Decisiones de arquitectura · Alternativas descartadas · Conceptos aplicados.

## Índice

### Nivel 0: Esqueleto

- [HEXM-0001: Esqueleto Laravel y PostgreSQL](HEXM-0001-esqueleto-laravel-y-postgresql.md): por qué la base de datos va en Docker y es un detalle de infraestructura.
- [HEXM-0002: Gate de calidad con Pest, PHPStan y Pint](HEXM-0002-gate-de-calidad-con-pest-phpstan-y-pint.md): por qué un gate único, el análisis estático al máximo y los tests contra PostgreSQL.
- [HEXM-0003: Estructura hexagonal y tests de arquitectura](HEXM-0003-estructura-hexagonal-y-tests-de-arquitectura.md): por qué el núcleo vive en `src/`, separado por contextos y capas, y por qué la regla de dependencias es un test.
- [HEXM-0004: Tests de arquitectura con lista blanca](HEXM-0004-tests-de-arquitectura-con-lista-blanca.md): por qué prohibir Laravel no basta y el núcleo solo puede usar lo autorizado.

### Nivel 1: Recetario (dominio)

- [HEXM-0005: Value objects del recetario](HEXM-0005-value-objects-del-recetario.md): por qué cantidades, raciones, pasos e identificadores son tipos propios que no pueden ser inválidos, por qué las fracciones son exactas y por qué el dominio genera la identidad.
