# Explicación HEXM-0002: Gate de calidad con Pest, PHPStan y Pint

## Explicación

Un proyecto que crece necesita una forma objetiva de decidir si un cambio se puede integrar. Esta tarea crea un **gate de calidad**: un único comando (`composer ci:check`) que comprueba el formato, analiza los tipos sin ejecutar el código y pasa los tests. Si falla cualquier paso, el cambio no se integra. `/close-task` lo ejecuta antes de cada cierre, y será el mismo comando que ejecute el CI cuando exista.

De paso se resuelve una decisión pendiente (deuda técnica #190): **los tests se ejecutan contra PostgreSQL**, no contra SQLite.

## Lógica de negocio

No hay reglas de negocio nuevas, pero el gate existe para protegerlas. Más adelante tendremos reglas como:

- "Un plan tiene exactamente dos semanas que se alternan."
- "El sábado es libre."
- "La grasa, solo en la comida o en la cena, no en ambas."

Cada regla se convertirá en un test. El gate garantiza que **ningún cambio posterior rompa una regla que ya funcionaba** sin que se note. Y como trabajaremos con datos que van a PostgreSQL (planes con fechas de vigencia y, en el futuro, vectores de recetas), los tests deben ejecutarse en ese mismo motor para que su resultado signifique algo.

## Decisiones de arquitectura

```
composer ci:check
  │
  ├─ 1. pint --test        ¿formato correcto?           (milisegundos)
  ├─ 2. phpstan analyse    ¿tipos coherentes?           (segundos)
  └─ 3. php artisan test   ¿se cumple el comportamiento? (segundos, necesita PostgreSQL)
          │
          ├─ tests/Unit     → dominio, SIN Laravel ni base de datos (desde HEXM-0003)
          └─ tests/Feature  → Laravel completo + PostgreSQL (hexagonal_test)
```

1. **El orden va de lo más barato a lo más caro.** Un error de formato se detecta en milisegundos; no tiene sentido esperar a los tests para descubrirlo. El gate se para en el primer fallo.

2. **El análisis estático al nivel máximo es una herramienta de arquitectura, no solo de estilo.** En hexagonal, las fronteras entre capas se expresan con tipos: un puerto es una interfaz y un value object es una clase con un tipo preciso. PHPStan al nivel `max` comprueba que cada adaptador implementa exactamente su puerto, que no se pasa un `string` donde el dominio espera un `RecipeId` y que no se olvida un `null`. Empezar en `max` con el proyecto vacío no cuesta nada; subir de nivel con cientos de clases escritas sí.

3. **Unit frente a Feature refleja las capas.** `tests/Unit` no arranca Laravel: ahí vivirán los tests del dominio, que no depende del framework. `tests/Feature` arranca Laravel y usa la base de datos: ahí vivirán los tests de los adaptadores. Si un test de dominio necesitara Laravel, sería la señal de que algo está en la capa equivocada.

4. **Los tests usan PostgreSQL en una base de datos propia** (ADR 0002). Es el mismo motor que en desarrollo, pero en `hexagonal_test`, porque los tests borran y recrean las tablas y no deben tocar los datos de desarrollo. Un test (`TestDatabaseTest`) protege la decisión.

5. **Qué no se analiza, y por qué.** `config/` queda fuera de PHPStan: son arrays declarativos que leen `env()`, que devuelve `mixed` por diseño, y la única forma de "arreglarlo" sería poner casts para silenciar avisos, lo contrario de lo que buscamos. `tests/` también queda fuera, porque las closures de Pest usan `$this` dinámico. Excluir con una razón explícita es mejor que ignorar errores uno a uno o mantener un *baseline* de errores aceptados.

## Alternativas descartadas

- **Lo habitual en un Laravel recién creado: PHPUnit sin análisis estático y con SQLite en memoria.** Es rápido de montar, pero sin PHPStan los errores de tipos aparecen en ejecución, y con SQLite los tests no prueban el motor real. En un proyecto hexagonal, donde la corrección de los contratos (puertos) es el centro del diseño, el análisis estático es imprescindible.
- **PHPUnit en lugar de Pest.** Funcionaría igual (Pest usa PHPUnit por debajo), pero Pest es más legible y, sobre todo, trae `arch()`, los tests de arquitectura que necesitamos en HEXM-0003 para comprobar la regla de dependencias.
- **PHPStan a un nivel intermedio (5 o 6), subiendo poco a poco.** Es lo razonable en un proyecto antiguo. En uno nuevo, cualquier nivel por debajo del máximo es deuda que se acumula.
- **Silenciar el error de `config/filesystems.php` con un cast o un `@phpstan-ignore`.** Habría dejado el análisis "en verde" a base de esconder información. El fichero no es código nuestro.
- **Un segundo contenedor de PostgreSQL para los tests.** Daría más aislamiento, pero obliga a gestionar dos servicios y dos puertos. Una base de datos separada en el mismo contenedor ofrece el aislamiento que necesitamos (los datos) con la mitad de infraestructura.
- **Un script `ci.sh` en lugar de scripts de Composer.** Composer ya es el punto de entrada de cualquier proyecto PHP; con él, `composer ci:check` funciona igual en local y en CI, y `composer list` lo documenta.

## Conceptos aplicados

| Concepto | Qué es | Dónde verlo |
|---|---|---|
| Gate de calidad | Un único comando que decide si un cambio se puede integrar | `app-laravel/composer.json` (`ci:check`), `docs/TAREAS.md` |
| Análisis estático | Detectar errores leyendo el código sin ejecutarlo | `app-laravel/phpstan.neon` |
| Larastan | Extensión de PHPStan que entiende la "magia" de Laravel | `app-laravel/phpstan.neon` (`includes`) |
| Pest | Framework de tests sobre PHPUnit, con sintaxis de frases y tests de arquitectura | `app-laravel/tests/Pest.php` |
| Tests de Unit frente a Feature | Aislados y sin framework, frente a integrados con Laravel y la base de datos | `app-laravel/tests/Pest.php`, `tests/Feature/` |
| Formato automático | Pint aplica un estilo único; nadie discute de espacios en una revisión | `app-laravel/pint.json` |
| Base de datos de tests | Mismo motor que en producción, en una base de datos aislada | `app-laravel/phpunit.xml`, `docs/adr/0002-base-de-datos-de-los-tests.md` |
| Test que protege una decisión | Un test que falla si alguien deshace una decisión de arquitectura | `app-laravel/tests/Feature/TestDatabaseTest.php` |
| Scripts de inicialización de Docker | Se ejecutan solo la primera vez que se crea el volumen | `docker/postgres/init/01-create-test-database.sh` |
