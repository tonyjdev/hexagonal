# 0003. Arquitectura hexagonal con el núcleo en `src/`

**Fecha:** 2026-10-31 · **Estado:** aceptada · **Tarea:** HEXM-0003

## Contexto

El objetivo del proyecto es aprender arquitectura hexagonal (puertos y adaptadores) con Laravel. En un Laravel clásico la lógica de negocio vive en `app/`, mezclada con el framework: modelos Eloquent que son a la vez entidad y persistencia, controladores con reglas de negocio, facades por todas partes… Funciona, pero el negocio queda atado al framework y a la base de datos: no se puede probar sin arrancar Laravel ni cambiar una pieza sin tocar las demás.

El dominio de este proyecto tiene dos partes claramente separadas (el recetario y los planes nutricionales) y, desde el bloque B, habrá adaptadores hacia servicios en Python. Ambas cosas exigen fronteras explícitas.

## Decisión

1. **El núcleo vive en `app-laravel/src/`, fuera de `app/`.** `app/` queda para lo que es de Laravel.
2. **Un directorio y un namespace raíz por *bounded context*:** `Recipes\`, `MealPlanning\` y `Shared\` (*shared kernel*), cada uno registrado en el autoload PSR-4 de `composer.json`.
3. **Tres capas por contexto:** `Domain`, `Application` e `Infrastructure`.
4. **Regla de dependencias**, siempre hacia dentro:
   - `Domain` no usa Laravel (`Illuminate\`, `App\`, `Database\` ni las funciones globales como `now()` o `collect()`) ni las capas exteriores.
   - `Application` no usa Laravel ni `Infrastructure`.
   - El `Domain` y la `Application` de un contexto no usan otro contexto. La comunicación entre contextos se hace en `Infrastructure`, mediante un adaptador que llama a la capa de aplicación del otro contexto.
   - `Shared` no usa ningún contexto.
5. **Las reglas son tests** (`tests/Architecture/HexagonalLayersTest.php`, con `arch()` de Pest) dentro de `composer ci:check`, y PHPStan analiza `src/` al nivel `max`.

## Consecuencias

- **A favor:**
  - El dominio se prueba sin Laravel y sin base de datos (tests rápidos en `tests/Unit`).
  - Cambiar un adaptador (Eloquent, un cliente HTTP o el proveedor del LLM) no toca el núcleo.
  - Las fronteras entre contextos son visibles y se comprueban solas.
- **En contra:**
  - Más ficheros y más indirección que en un Laravel clásico: una entidad de dominio y, aparte, un modelo Eloquent con un *mapper* entre los dos; un puerto y su adaptador.
  - Se renuncia a la comodidad de Eloquent y de las facades dentro del núcleo.
  - Es el precio consciente del aprendizaje y del desacoplamiento.
- **Riesgo conocido:** en Pest 5, `expect([...array de namespaces...])->not->toUse(...)` no detecta violaciones. Los tests de arquitectura comprueban cada namespace por separado (está documentado en el propio test).
- **Mantenimiento:** un contexto nuevo requiere añadirlo al autoload de `composer.json`, a `businessContexts()` en el test de arquitectura y al glosario de `docs/DOMINIO.md`.
