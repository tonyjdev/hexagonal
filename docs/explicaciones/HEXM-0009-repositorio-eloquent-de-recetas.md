# Explicación HEXM-0009: Repositorio Eloquent de recetas

## Explicación

Hasta el nivel 1, las recetas solo existían en memoria. El caso de uso `CreateRecipe` guardaba a través del puerto `RecipeRepository`, y su único adaptador era un array de PHP. Esta tarea añade un **segundo adaptador**, `EloquentRecipeRepository`, que guarda las recetas en PostgreSQL. Es la prueba de fuego de la arquitectura hexagonal: la base de datos entra en el proyecto **sin cambiar ni una línea** de `Recipe`, de `CreateRecipe` ni del puerto (`git diff develop` sobre `Domain` y `Application` sale vacío).

La idea de la solución:

- el agregado `Recipe` se reparte en tres tablas;
- un *mapper* traduce entre el agregado y las filas, en los dos sentidos;
- el repositorio guarda la receta entera en **una transacción**;
- unos **tests de contrato** garantizan que el adaptador en memoria y el de PostgreSQL se comportan igual.

## Lógica de negocio

Las reglas que la persistencia tiene que respetar son las del recetario (reglas 8 a 12 de `docs/DOMINIO.md`):

- **Las cantidades son exactas.** El plan dice "½ bote de lentejas" o "1 ½ cucharada de aceite". Si se guardaran como decimales (0.5, 1.5), ⅓ de taza se convertiría en 0.333… y se perdería precisión al sumar la lista de la compra (bloque C). Por eso, ½ bote se guarda como numerador 1, denominador 2 y unidad "bote".
- **"Al gusto" no es una cantidad.** "Sal al gusto" o "una pizca de pimienta" se guardan sin número ni unidad. No vale "media cantidad": o hay número, denominador y unidad, o no hay ninguno de los tres.
- **El orden importa.** Los pasos de una receta se siguen en orden ("calentar el pisto" va antes de "añadir las lentejas"), y los ingredientes se muestran como los escribió la persona.
- **Una receta es un todo.** Una receta con su nombre nuevo pero sin pasos, o con la mitad de los ingredientes, no existe en el negocio. Si guardar falla a mitad, tiene que quedar la versión anterior completa, o nada si la receta era nueva.
- **Modificar una receta la reemplaza.** Si la "versión ligera" de las lentejas pasa de cuatro ingredientes a dos, después de guardarla no puede quedar ningún resto de la versión anterior.

## Decisiones de arquitectura

**Por qué cada pieza está en su capa:**

- `RecipeRepository` (el puerto) sigue en `Recipes\Domain`. Es lo que el núcleo necesita ("guárdame esta receta") y no sabe cómo se hace.
- `EloquentRecipeRepository`, `RecipeMapper` y los tres modelos de Eloquent (`RecipeModel`, `RecipeIngredientModel`, `RecipeStepModel`) viven en `Recipes\Infrastructure\Persistence\Eloquent`. Son **detalles de cómo se guarda**: conocen el dominio y Eloquent, pero el dominio no los conoce a ellos. Por eso los modelos no están en `app/Models`. Un test de arquitectura nuevo prohíbe usar `Illuminate\Database\Eloquent` fuera de las capas `Infrastructure`, `app/` y `database/`, y se ha comprobado que detecta un modelo colocado en `Application`.
- La migración está en `database/migrations`, porque es infraestructura de Laravel.

**Sentido de las dependencias:**

```
EloquentRecipeRepository ──implements──► RecipeRepository (Domain)
        │                                       ▲
        ├──► RecipeMapper ──► Recipe, Ingredient, Quantity… (Domain)
        └──► RecipeModel ──► Eloquent ──► PostgreSQL
                                                │
CreateRecipe (Application) ─────────────────────┘ solo conoce el puerto
```

Las flechas de código solo apuntan hacia el dominio. La llamada en tiempo de ejecución sale hacia PostgreSQL, pero el código de `Domain` y `Application` no importa nada de Eloquent: es la inversión de dependencias.

**Recorrido de `save(receta)` de principio a fin:**

1. Se abre una transacción en la conexión del propio modelo, para que la transacción y las consultas no puedan ir por conexiones distintas.
2. El *mapper* convierte la receta en filas: la cabecera, los ingredientes con su posición y su fracción, y los pasos con su posición.
3. `updateOrCreate` sobre `recipes`: hace `UPDATE` si la receta existe e `INSERT` si es nueva.
4. Se borran los ingredientes y pasos anteriores, y se insertan los actuales en bloque, con un `INSERT` por tabla.
5. `COMMIT`. Si cualquier paso lanza una excepción, se hace `ROLLBACK` y la excepción sube a quien llamó.

**Recorrido de `find(id)`:**

1. Tres consultas: la receta, y con `with()` sus ingredientes y sus pasos, ordenados por `position`.
2. `RecipeMapper::toDomain()` reconstruye la receta con los **mismos constructores con nombre del dominio**: `Recipe::create()`, `Ingredient::of()` o `Ingredient::toTaste()`, `Quantity::fraction()` y `Step::fromString()`.

**Reconstitución con `Recipe::create()`.** La pregunta abierta de la tarea era si cargar una receta debía pasar por las validaciones o saltárselas con un constructor especial. Se ha elegido validar:

- cuesta nada;
- no obliga a tocar el dominio;
- y si la base de datos contiene algo inválido (un `UPDATE` hecho a mano), es mejor que falle al leer que dejar circular una receta corrupta.

Habrá que revisarlo cuando `create()` haga algo que no deba repetirse al cargar, como registrar un evento `RecipeCreated` (ADR 0006).

**Esquema.**

- `recipes.id` es un `uuid` que llega del dominio (ADR 0005).
- `recipe_ingredients` y `recipe_steps` usan una **clave primaria compuesta `(recipe_id, position)`**, sin `id` propio, porque `Ingredient` y `Step` son *value objects* sin identidad.
- La cantidad se guarda en tres columnas (`numerator`, `denominator`, `unit`).
- Un `CHECK` de PostgreSQL obliga a que esas tres columnas sean todas nulas o todas presentes y positivas. Es una **segunda red** para lo que no pasa por el dominio.

**El `CHECK` y los `NULL` de SQL.** La prueba manual destapó que la primera versión del `CHECK` aceptaba `(1, NULL, 'jar')`. En SQL, `NULL > 0` no da `false` sino `NULL`, y un `CHECK` solo rechaza lo que da `false`. Se corrigió exigiendo cada columna con `IS NOT NULL`, y el test cubre ahora las siete combinaciones inválidas. Mientras tanto, el *mapper* hizo de última red: al leer esa fila lanzó `UnexpectedValueException` en vez de inventar una cantidad.

**Tests de contrato.** Una interfaz de PHP solo obliga a tener los métodos con la firma correcta, no a comportarse de una manera. La misma batería de 7 casos se ejecuta contra los dos adaptadores mediante un *dataset* de Pest:

- devolver `null` si la receta no existe;
- devolver los mismos datos que se guardaron;
- guardar una receta sin pasos;
- no ver cambios hasta volver a guardar;
- reemplazar la receta entera al guardarla de nuevo;
- que modificar la copia devuelta no cambie lo guardado;
- mantener separadas recetas distintas.

Así, los tests rápidos de `CreateRecipe`, que usan el adaptador en memoria, valen también para producción.

## Alternativas descartadas

- **El "Laravel clásico": `App\Models\Recipe` como modelo de negocio.** Las reglas acabarían repartidas en controladores y FormRequests, con setters que permiten dejar una receta sin ingredientes. Además, el dominio dependería de Eloquent y no se podría probar sin base de datos. Aquí el modelo de Eloquent es solo una fila; la receta con sus reglas es `Recipes\Domain\Recipe`.
- **`Recipe::reconstitute()` sin validaciones.** Ahorraría unas comprobaciones baratísimas, pero obligaría a tocar el dominio y dejaría pasar datos corruptos. Se pospone hasta que haya un motivo real, que será un evento de dominio en `create()`.
- **Una columna `id` autoincremental en ingredientes y pasos.** Es lo habitual en Laravel, pero daría identidad a algo que no la tiene y tentaría a actualizar "el ingrediente 37". La clave compuesta refleja el dominio: el ingrediente número N de la receta X.
- **Actualizar fila a fila solo lo que cambió.** Es más complejo y no aporta nada con una decena de filas, porque no hay "este ingrediente de antes" que conservar. Se reemplazan en bloque.
- **La cantidad como `decimal` o `float`.** No representa ⅓ y rompe la exactitud que garantiza `Quantity`.
- **La unidad como `ENUM` de PostgreSQL.** Añadir una unidad al dominio obligaría a un `ALTER TYPE`. Basta con texto y con que `Unit::from()` falle si llega un valor desconocido.
- **`DB::transaction()` con la conexión por defecto.** Funciona hoy, pero si el recetario se moviera a otra conexión, la transacción y las consultas irían por caminos distintos sin que nada avisara.
- **Mantener `InMemoryRecipeRepositoryTest` además del contrato.** Sus casos ya forman parte del contrato. Mantener las dos baterías sería duplicar.

## Conceptos aplicados

| Concepto | Definición breve | Dónde verlo |
|---|---|---|
| Adaptador *driven* (segundo adaptador del mismo puerto) | Implementación de un puerto de salida que el núcleo usa sin saber cuál es. | `app-laravel/src/Recipes/Infrastructure/Persistence/Eloquent/EloquentRecipeRepository.php` |
| Data Mapper | Traductor entre el modelo de dominio y el modelo de datos, para que ninguno dependa del otro. | `.../Persistence/Eloquent/RecipeMapper.php` |
| Reconstitución de agregados | Reconstruir un agregado guardado. Aquí se hace con los constructores que validan. | `RecipeMapper::toDomain()` y ADR 0006 |
| Modelo de persistencia frente a modelo de dominio | El modelo de Eloquent es una fila, no el negocio. | `.../Persistence/Eloquent/RecipeModel.php` |
| Transacción por agregado | Guardar un agregado es todo o nada. | `EloquentRecipeRepository::save()` y los tests de rollback en `tests/Feature/Recipes/Infrastructure/EloquentRecipeRepositoryTest.php` |
| Clave compuesta para *value objects* | Filas sin identidad propia, identificadas por (padre, posición). | Migración `2026_11_01_000000_create_recipes_tables.php` y `RecipeIngredientModel` (`#[Table(key: false)]`) |
| Restricciones como segunda red y lógica de tres valores | `CHECK` en la base de datos. `NULL` en una comparación da `NULL`, y un `CHECK` lo deja pasar. | Migración (bloque `CONCEPTO: lógica de tres valores`) |
| Tests de contrato | Una especificación del puerto que cumplen todos sus adaptadores. | `tests/Feature/Recipes/Infrastructure/RecipeRepositoryContractTest.php` |
| Object Mother | Fábrica de objetos de ejemplo válidos para los tests. | `tests/Support/Recipes/RecipeMother.php` |
| Test de arquitectura en positivo | Eloquent solo se usa donde está permitido. | `tests/Architecture/HexagonalLayersTest.php` (`Eloquent is only used in infrastructure`) |
| Atributos de Eloquent (Laravel 13) | `#[Table]` y `#[Unguarded]` en lugar de las propiedades `$table`, `$primaryKey`… | Los tres modelos |

## Diagramas

- [Tablas del recetario](../diagramas/HEXM-0009/tablas-del-recetario.erd.json) (`erd`): ¿cómo se reparte el agregado `Recipe` en la base de datos? Muestra el uuid de la raíz, la clave compuesta de ingredientes y pasos, la cantidad exacta en tres columnas y la cardinalidad (al menos un ingrediente, pasos opcionales).
- [Guardar y cargar una receta](../diagramas/HEXM-0009/guardar-y-cargar-una-receta.sequence.json) (`sequence`): ¿qué pasa al llamar a `save()` y a `find()`? Muestra la transacción, el `ROLLBACK` si algo falla a mitad y la reconstitución con `Recipe::create()`.

Para verlos: `cd app-laravel && composer docs:diagrams` y abrir `docs/diagramas/HEXM-0009/tablas-del-recetario.html` y `docs/diagramas/HEXM-0009/guardar-y-cargar-una-receta.html`.
