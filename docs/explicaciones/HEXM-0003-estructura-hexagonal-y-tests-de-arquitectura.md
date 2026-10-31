# Explicación HEXM-0003: Estructura hexagonal y tests de arquitectura

## Explicación

La arquitectura hexagonal se resume en una frase: **el negocio en el centro y todo lo demás (framework, base de datos, HTTP, servicios externos) alrededor, como piezas intercambiables**. Dicha así es solo una intención, y las intenciones se erosionan: basta un `use Illuminate\Support\Facades\DB;` en una entidad, hecho con prisa, para que el dominio vuelva a estar atado a Laravel.

Esta tarea hace dos cosas:

1. **Da forma física a la arquitectura**: una carpeta `src/` fuera de `app/`, un directorio por contexto (`Recipes`, `MealPlanning`, `Shared`) y tres capas en cada uno (`Domain`, `Application`, `Infrastructure`).
2. **Convierte la regla de dependencias en tests** que se ejecutan en cada `composer ci:check`. Desde ahora, romper la arquitectura no es "una mala práctica": es un test en rojo que impide integrar el cambio.

Todavía no hay ni una clase de negocio. Es a propósito: las reglas se ponen **antes** de que exista código que las pueda violar, igual que se ponen los cimientos antes que las paredes.

## Lógica de negocio

No hay reglas de negocio nuevas, pero sí una decisión sobre **cómo se divide el negocio**, que sale directamente del problema real:

- **El recetario** tiene sentido por sí solo. Una receta de *guiso de alubias y pollo* tiene ingredientes, cantidades, pasos y raciones, y existe tanto si la nutricionista la incluye en el plan 27 como si no.
- **Los planes** (el plan 27, sus dos semanas que se alternan, el sábado libre, los intercambios, las pautas fijas del desayuno) son otro mundo, con su propio vocabulario. Cuando un plato del menú 3 dice "guiso de alubias", al plan solo le interesa **saber cuál es la receta**, no cómo se cocina.

Por eso son dos *bounded contexts* separados y el de planes solo conoce una receta por su identificador (`RecipeId`). Si mañana el recetario cambia cómo guarda los pasos, los planes ni se enteran. Y si en el futuro aparece una lista de la compra, podrá ser un tercer contexto que pide datos a los otros dos sin acoplarse a ellos.

## Decisiones de arquitectura

### Por qué `src/` y no `app/`

`app/` es de Laravel: allí viven sus modelos, sus providers y sus controladores. Si el dominio estuviera en `app/Domain`, nada impediría mezclarlo con el framework. En `src/` la separación es visible incluso desde el explorador de ficheros, y el autoload PSR-4 lo deja explícito con un namespace raíz por contexto (`Recipes\`, `MealPlanning\`, `Shared\`) en lugar de `App\Domain\...`. Laravel pasa a ser **un adaptador más**.

### Las capas y hacia dónde apuntan las dependencias

```
                ┌──────────────── Recipes ────────────────┐   ┌──────────── MealPlanning ────────────┐
                │                                         │   │                                      │
 HTTP/Artisan ─►│ Infrastructure ─► Application ─► Domain │◄──│─ Infrastructure ─► Application ─► Domain
                │  (Laravel,         (casos de     (reglas│   │  (adaptador que     (casos de     (reglas,
                │   Eloquent)         uso)          puras)│   │   llama a Recipes)   uso)          RecipeId)
                └─────────────────────────────────────────┘   └──────────────────────────────────────┘
                                         │                                     │
                                         └──────────► Shared (kernel) ◄────────┘
                                                      no depende de nadie
```

| Regla | Por qué |
|---|---|
| `Domain` no usa Laravel ni las capas exteriores | Para probar las reglas del negocio (por ejemplo, "el sábado es libre") en milisegundos, sin arrancar el framework ni una base de datos. |
| `Domain` tampoco usa las **funciones globales** de Laravel (`now()`, `collect()`, `config()`…) | Acoplan igual que un `use`, pero no se ven en las importaciones. `now()` además haría imposible probar el calendario con una fecha fija; para eso vendrá el puerto `Clock`. |
| `Application` no usa Laravel ni `Infrastructure` | Los casos de uso orquestan el dominio a través de **puertos** (interfaces). Quién implementa el puerto (Eloquent, un fake en memoria o un servicio en Python) se decide fuera. |
| El núcleo de un contexto no usa otro contexto | Los contextos evolucionan por separado. La única puerta entre ellos es un adaptador en `Infrastructure` que llama a la capa de **aplicación** del otro, nunca a su dominio. |
| `Shared` no usa ningún contexto | Si el kernel compartido dependiera de `Recipes`, todos los contextos quedarían acoplados a `Recipes` a través de él. |

### Cómo se comprueban: tests de arquitectura

`tests/Architecture/HexagonalLayersTest.php` usa `arch()` de Pest: analiza el código **sin ejecutarlo** y comprueba los `use`, los tipos y las llamadas de cada clase. Las reglas se escriben una vez y se aplican a cada contexto con *datasets* (`Recipes`, `MealPlanning`, `Shared`), de modo que añadir un contexto nuevo es añadir un nombre a `businessContexts()`.

Es una suite propia (`Architecture`) que no arranca Laravel ni toca la base de datos, y PHPStan analiza ahora también `src/` al nivel `max`.

### El falso negativo: por qué cada capa se comprueba por separado

Para validar los tests se provocaron violaciones a propósito. La regla entre contextos estaba escrita al principio así:

```php
expect(['MealPlanning\Domain', 'MealPlanning\Application'])->not->toUse('Recipes');
```

Y **no detectaba** que una clase de `MealPlanning\Domain` importara el dominio de `Recipes`. Aislando el caso se comprobó que, en Pest 5, un *array* como objetivo de `expect()` en `not->toUse()` no encuentra la violación, mientras que con un único namespace sí. La solución fue un *dataset* contexto × capa, con una sola expectativa por namespace. Queda documentado en el test y en el ADR 0003.

La lección es más importante que el detalle de Pest: **un test que nunca has visto fallar no demuestra nada**. Por eso el criterio de aceptación pedía romper el código a propósito.

## Alternativas descartadas

- **El Laravel clásico (todo en `app/`):** modelos Eloquent que son a la vez entidad de negocio y fila de la base de datos, y reglas repartidas entre controladores, *form requests* y modelos. Es productivo para un CRUD, pero el negocio queda atado al framework: no se puede probar sin la base de datos ni cambiar una pieza sin tocar las demás. Es justo lo que este proyecto quiere aprender a evitar.
- **`app/Domain`, `app/Application`…:** separa en carpetas, pero sigue dentro del namespace `App\` y del territorio del framework. La frontera sería solo una convención.
- **Organizar primero por capa y después por contexto** (`src/Domain/Recipes`, `src/Domain/MealPlanning`…): pone juntas cosas que no tienen relación (recetas y calendarios) y separa lo que cambia a la vez (el dominio de recetas y su adaptador Eloquent). Organizar por contexto hace visibles las fronteras del negocio.
- **Un paquete de Composer por contexto:** la máxima separación, pero excesiva para un proyecto de aprendizaje con dos contextos. Si algún día hiciera falta, la estructura actual se puede extraer sin reescribir nada.
- **deptrac** (herramienta específica de reglas de capas): muy potente, pero sería otra herramienta y otra configuración más. Pest `arch()` ya forma parte del gate y escribe las reglas en PHP, junto al resto de los tests.
- **Fiarlo a la revisión de código:** las reglas que solo están en un documento se olvidan. Un test no se olvida.

## Conceptos aplicados

| Concepto | Definición breve | Dónde verlo |
|---|---|---|
| Arquitectura hexagonal (puertos y adaptadores) | El núcleo de negocio no depende de la tecnología; la tecnología se enchufa a él mediante puertos (interfaces) y adaptadores (implementaciones). | `app-laravel/src/README.md`, ADR 0003 |
| Regla de dependencias | Las dependencias solo apuntan hacia dentro: Infrastructure → Application → Domain. | `src/README.md` (tabla de capas), `tests/Architecture/HexagonalLayersTest.php` |
| *Fitness functions* (tests de arquitectura) | Tests que comprueban cómo está construido el código, no lo que hace. | `tests/Architecture/HexagonalLayersTest.php` (`CONCEPTO:` en la cabecera) |
| *Bounded context* | Una parte del negocio con su propio modelo y vocabulario, separada de las demás. Aquí, un directorio y un namespace raíz por contexto. | `src/Recipes/`, `src/MealPlanning/`, `docs/DOMINIO.md` §2 |
| *Shared kernel* | Código mínimo común a varios contextos, acordado explícitamente, que no depende de ninguno. | `src/Shared/README.md`, test `the shared kernel does not depend on any context` |
| Comunicación entre contextos por identificador | Un contexto solo conoce del otro su identificador (`RecipeId`) y le pide datos a través de un puerto propio. | `src/MealPlanning/README.md` |
| ADR (*Architecture Decision Record*) | Registro breve de una decisión: contexto, decisión y consecuencias. | `docs/adr/0003-arquitectura-hexagonal-en-src.md` |
