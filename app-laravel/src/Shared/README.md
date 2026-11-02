# Shared (Núcleo compartido)

Piezas **mínimas** que necesitan varios contextos. En DDD se llama *shared kernel*: un acuerdo explícito entre contextos sobre un pequeño trozo de código común.

| Capa | Ejemplos de lo que puede ir aquí |
|---|---|
| `Domain/` | La excepción base `DomainException`, el tipo `Uuid` (UUID v7, HEXM-0012) y, más adelante, la interfaz `DomainEvent`… |
| `Application/` | La excepción base `NotFound` (HEXM-0011, → HTTP 404) y puertos transversales, como un `EventBus` o un `TransactionManager`. |
| `Infrastructure/` | Sus adaptadores con Laravel. |

**Regla:** `Shared` no depende de ningún contexto (los tests de arquitectura lo comprueban). Antes de mover algo aquí, conviene preguntarse si es *de verdad* común o si solo se parece: duplicar un poco es mejor que acoplar dos contextos por una abstracción prematura.

**Ejemplo de las dos caras (HEXM-0012):** `Uuid` sí se extrajo aquí, porque es pura técnica sin significado de negocio y dos contextos repetirían exactamente el mismo código. `Quantity` **no**: una cantidad es un concepto del negocio que cada contexto modela a su manera (exacta en el recetario, texto libre en los platos de un plan).
