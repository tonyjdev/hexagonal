# Nivel 00: Esqueleto

**Fecha:** 2026-10-31 · **Etiqueta git:** `nivel-00`

Tareas: HEXM-0001, HEXM-0002 y HEXM-0003, más HEXM-0004 como refuerzo posterior a la etiqueta (ver el anexo).

## Qué se ha construido

- **Laravel 13 (PHP 8.5) en `app-laravel/`** y **PostgreSQL 18 con pgvector en Docker Compose**, en el puerto `5433` del host para no chocar con el PostgreSQL nativo. Un script de arranque crea la base de datos de tests `hexagonal_test` (HEXM-0001 y HEXM-0002).
- **Gate de calidad `composer ci:check`:** Pint (formato), Larastan al nivel `max` (tipos) y Pest (tests), con los tests ejecutándose contra PostgreSQL (HEXM-0002).
- **Estructura hexagonal en `src/`:** los contextos `Recipes`, `MealPlanning` y `Shared`, cada uno con `Domain`, `Application` e `Infrastructure`, y un README que dice qué irá en cada capa (HEXM-0003).
- **Tests de arquitectura** que convierten la regla de dependencias en una comprobación automática dentro del gate (HEXM-0003).
- El sistema de trabajo: tareas `HEXM` en Workspace, los tres documentos de cierre (Resultado, Pruebas y Explicación) y las ramas `develop` / `main` (con `staging` prevista para el primer despliegue).

Todavía no hay ni una línea de negocio, y es lo esperado: este nivel prepara el terreno y las reglas.

## Conceptos aprendidos

- **Arquitectura hexagonal y regla de dependencias:** el negocio en el centro; las dependencias solo apuntan hacia dentro (Infrastructure → Application → Domain).
- ***Bounded contexts* como módulos:** recetario y planes son dos modelos distintos; los planes solo conocen una receta por su `RecipeId`.
- ***Shared kernel*:** lo común, mínimo y sin depender de ningún contexto.
- ***Fitness functions*:** tests que comprueban la forma del código, no su comportamiento.
- **La base de datos como detalle de infraestructura:** se levanta con Docker y el núcleo no sabe que existe.
- **Gate de calidad:** un único comando que decide si un cambio se integra, el mismo en local y en el futuro CI.
- **ADR:** las decisiones importantes se escriben con su contexto y sus consecuencias (0001 ramas, 0002 base de datos de tests, 0003 arquitectura en `src/`).

## Decisiones tomadas (y por qué)

- **Laravel 13 en vez de 12:** era la versión estable más reciente y la que ya se usa en otros proyectos.
- **PostgreSQL también en los tests** (ADR 0002): SQLite se comporta distinto (tipos, fechas y, más adelante, pgvector) y daría falsos verdes.
- **`config/` y `tests/` fuera de PHPStan:** `config/` son arrays declarativos que leen `env()` y obligarían a añadir *casts* sin valor; los tests de Pest usan closures que PHPStan no sigue sin un plugin.
- **El núcleo en `src/` y organizado por contexto** (ADR 0003): la frontera con Laravel es visible y los contextos evolucionan por separado.
- **Laravel Boost, fuera por ahora:** sus instrucciones para agentes competían con las del proyecto.
- **Ramas `develop` → `staging` → `main`** (ADR 0001), preparando un CI/CD futuro.

## Dificultades / dudas

- **El falso negativo de Pest 5:** `expect([varios namespaces])->not->toUse(...)` no detectaba una violación entre contextos. Se descubrió al romper el código a propósito y se corrigió comprobando cada namespace por separado. Lección: un test que nunca has visto fallar no demuestra nada.
- **PostgreSQL 18 cambió la ruta de datos:** el volumen se monta en `/var/lib/postgresql`, no en `/var/lib/postgresql/data`.
- **El script de inicialización solo se ejecuta con un volumen vacío:** con una base de datos ya creada, `hexagonal_test` hay que crearla a mano (`createdb`), como indica el README.

## Para profundizar

- Alistair Cockburn, *Hexagonal Architecture* (el artículo original de "ports and adapters").
- Eric Evans, *Domain-Driven Design*, capítulos sobre *bounded contexts* y *context mapping*.
- Vaughn Vernon, *Implementing Domain-Driven Design*, capítulo de arquitectura.
- Neal Ford y otros, *Building Evolutionary Architectures* (*fitness functions*).
- Documentación de Pest: *Architecture Testing*.
- Ejercicio opcional: crea `src/Recipes/Domain/Probe.php` con `use Illuminate\Support\Collection;`, ejecuta `php artisan test --testsuite=Architecture` y lee el mensaje de error. Después bórralo.

## Anexo: HEXM-0004, lista blanca de dependencias

Al repasar cómo detectan los tests "que no se use Laravel", se comprobó que la lista negra dejaba pasar Carbon, `Laravel\Prompts` y cualquier librería que use Laravel por dentro. Se añadieron reglas de **lista blanca** (`toOnlyUse`): el núcleo solo puede usar PHP nativo, su propio código y las librerías autorizadas en un ADR (hoy, ninguna). Ver el [ADR 0004](../adr/0004-lista-blanca-de-dependencias-en-el-nucleo.md).

También se comprobó un límite: el código dinámico (`$fn()`, `new $class`) no lo detectan ni los tests de arquitectura ni PHPStan; queda para la revisión de código.

La etiqueta `nivel-00` no se ha movido (una etiqueta publicada no se reescribe): este refuerzo está en `develop` después de ella.
