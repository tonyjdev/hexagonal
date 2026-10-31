# 0004. Lista blanca de dependencias en el núcleo

**Fecha:** 2026-10-31 · **Estado:** aceptada · **Tarea:** HEXM-0004 · **Complementa:** [ADR 0003](0003-arquitectura-hexagonal-en-src.md)

## Contexto

El ADR 0003 convirtió la regla de dependencias en tests de arquitectura, pero escritos como una **lista negra**: `expect('Recipes\Domain')->not->toUse(['Illuminate', 'App', 'Database', 'now', …])`. Una lista negra solo prohíbe lo que se nos ha ocurrido prohibir. Con clases de prueba se comprobó que los tests seguían en verde con:

- `Carbon\CarbonImmutable` en el dominio (la librería de fechas que Laravel usa por debajo);
- `Laravel\Prompts\Prompt`, un paquete de Laravel con un namespace distinto de `Illuminate`;
- y, por extensión, cualquier librería de terceros que dependa de Laravel por dentro, porque el análisis no sigue las dependencias indirectas.

## Decisión

1. **El núcleo (`Domain` y `Application` de cada contexto, incluido `Shared`) se comprueba con una lista blanca**, mediante `toOnlyUse()` de Pest. Además de PHP nativo (clases como `DateTimeImmutable` y funciones como `array_map`, que siempre están permitidas), solo puede usar:

   | Capa | Lista blanca |
   |---|---|
   | `X\Domain` | `X\Domain`, `Shared\Domain` |
   | `X\Application` | `X\Domain`, `X\Application`, `Shared\Domain`, `Shared\Application` |

2. **Las reglas de lista negra se mantienen**: lo que detectan ya lo cubre la lista blanca, pero su mensaje de error es más claro ("no uses `Illuminate`").
3. **Autorizar una librería externa en el núcleo es una decisión de arquitectura**: se añade su namespace a `authorizedLibraries()` en `tests/Architecture/HexagonalLayersTest.php` y se justifica en un ADR nuevo. Hoy no hay ninguna autorizada.
4. **Una expectativa por namespace** (*dataset* contexto × capa), por el falso negativo de Pest 5 con arrays como objetivo de `expect()` descrito en el ADR 0003.

## Consecuencias

- **A favor:**
  - Cualquier dependencia nueva en el núcleo, de Laravel o no, directa o que arrastre Laravel por dentro, rompe el gate hasta que alguien la autoriza a propósito.
  - Las dependencias del núcleo quedan **explícitas** y documentadas en un único sitio.
- **En contra:**
  - Usar una librería razonable (por ejemplo, `ramsey/uuid`) exige un paso más: tocar el test y escribir un ADR. Es intencionado: el núcleo debe cambiar poco y sus dependencias deben pensarse.
- **Límite conocido:** el análisis es estático. El código dinámico (`new $class`, `$fn()`, `call_user_func('now')`) no se detecta con ninguna de las dos listas, y PHPStan al nivel `max` tampoco lo señala (comprobado en HEXM-0004: `$fn = 'now'; $fn()`, `new $class` y `call_user_func('collect')` dan 0 errores). La mitigación es la revisión de código: en el núcleo no hay ninguna razón legítima para escribirlo.
