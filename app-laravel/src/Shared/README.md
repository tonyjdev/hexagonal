# Shared (Núcleo compartido)

Piezas **mínimas** que necesitan varios contextos. En DDD se llama *shared kernel*: un acuerdo explícito entre contextos sobre un pequeño trozo de código común.

| Capa | Ejemplos de lo que puede ir aquí |
|---|---|
| `Domain/` | Una excepción base de dominio, un tipo `Uuid`, la interfaz `DomainEvent`… |
| `Application/` | Puertos transversales, como un `EventBus` o un `TransactionManager`. |
| `Infrastructure/` | Sus adaptadores con Laravel. |

**Regla:** `Shared` no depende de ningún contexto (los tests de arquitectura lo comprueban). Antes de mover algo aquí, conviene preguntarse si es *de verdad* común o si solo se parece: duplicar un poco es mejor que acoplar dos contextos por una abstracción prematura.
