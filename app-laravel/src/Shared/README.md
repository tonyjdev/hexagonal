# Shared (Núcleo compartido)

Piezas **mínimas** que necesitan varios contextos. En DDD se llama *shared kernel*: un acuerdo explícito entre contextos sobre un pequeño trozo de código común.

| Capa | Ejemplos de lo que puede ir aquí |
|---|---|
| `Domain/` | La excepción base `DomainException`, el tipo `Uuid` (UUID v7, HEXM-0012), la interfaz `DomainEvent` y el *trait* `RecordsEvents` con el que un agregado registra sus eventos (HEXM-0026, ADR 0018). |
| `Application/` | La excepción base `NotFound` (HEXM-0011, → HTTP 404) y puertos transversales: el reloj `Clock` (HEXM-0019, ADR 0012) y el bus de eventos `EventBus` (HEXM-0026, ADR 0018). |
| `Infrastructure/` | Sus adaptadores: `SystemClock` (el reloj real, con la zona horaria inyectada) y `FrozenClock` (una fecha fija, para los tests), `LaravelEventBus` (el *dispatcher* de Laravel, que publica después del *commit*) e `InMemoryEventBus` (tests), y `SharedServiceProvider`, que enchufa `Clock` a `SystemClock` con la zona de `config/clock.php` (HEXM-0022) y `EventBus` a `LaravelEventBus` (HEXM-0026). |

**Regla:** `Shared` no depende de ningún contexto (los tests de arquitectura lo comprueban). Antes de mover algo aquí, conviene preguntarse si es *de verdad* común o si solo se parece: duplicar un poco es mejor que acoplar dos contextos por una abstracción prematura.

**Ejemplo de las dos caras (HEXM-0012):** `Uuid` sí se extrajo aquí, porque es pura técnica sin significado de negocio y dos contextos repetirían exactamente el mismo código. `Quantity` **no**: una cantidad es un concepto del negocio que cada contexto modela a su manera (exacta en el recetario, texto libre en los platos de un plan).
