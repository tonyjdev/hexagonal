# Explicación HEXM-0011: Consulta de recetas por API

## Explicación

Hasta ahora todo lo que hacía la aplicación **cambiaba** algo: crear una receta y guardarla. Esta tarea añade la primera **consulta**, `GET /api/recipes/{id}`, que pregunta sin cambiar nada. Con ella se cierra el nivel 2: el recetario se guarda en PostgreSQL y se puede crear y consultar por HTTP.

Una consulta plantea preguntas que un comando no tenía:

- **Qué se devuelve.** La entidad `Recipe` tiene comportamiento (`rename()`…) y una estructura interna que no conviene exponer. Se devuelve un **DTO de lectura** (`RecipeView`): una foto de la receta con datos primitivos, pensada para mostrar.
- **Cómo se muestra una cantidad.** Se guarda como fracción exacta (3/2), pero una persona espera leer "1 ½". Esa traducción es conocimiento del recetario, así que la hace el dominio (`Quantity::amountAsText()`).
- **Qué pasa si no existe.** "No existe" no es un fallo ni una regla de negocio incumplida: es una de las dos respuestas posibles de la consulta. Tiene su propia familia de excepciones (`NotFound`) y llega al cliente como `404`.

## Lógica de negocio

Con el *pisto* de los planes: 2 raciones, un calabacín, ½ bote de tomate triturado, 1,5 cucharadas de aceite y sal al gusto.

- **Las cantidades se leen como se escriben en los planes.** El ½ bote se guardó exactamente como 1/2 y se muestra "½". El "1,5" de aceite se guardó como 3/2 y se muestra "1 ½", que es como lo escribe la nutricionista. Una cantidad como 5/7, sin carácter propio, se muestra "5/7".
- **Lo que se muestra se puede volver a leer.** Cualquier cantidad que la aplicación escribe, la aplicación la sabe leer y obtiene exactamente la misma. Así, una receta consultada se puede reenviar tal cual.
- **"Al gusto" se distingue explícitamente.** La sal no tiene cantidad ni unidad, y la receta lo dice claramente ("al gusto"), sin que quien la lee tenga que deducirlo de un hueco vacío.
- **Las unidades tienen dos nombres:** el código que entiende la aplicación ("jar") y el nombre que entiende una persona ("bote").
- **Consultar no cambia nada.** Se puede preguntar por una receta las veces que se quiera.
- **Tres respuestas posibles:**
  - la receta existe → se muestra;
  - el identificador es válido pero no hay ninguna receta con él → "No existe ninguna receta con el identificador «…»";
  - el identificador ni siquiera tiene forma de identificador (`abc`) → "\"abc\" no es un identificador de receta válido". Una receta así **no puede existir**, que no es lo mismo que **no existir**.

## Decisiones de arquitectura

Las cinco decisiones abiertas de la tarea las tomó el usuario a partir de una recomendación razonada. Están en el [ADR 0008](../adr/0008-consultas-dto-de-lectura-y-no-encontrado.md).

**Por qué cada pieza está en su capa:**

- `Quantity::amountAsText()` está en el **dominio** (`Recipes\Domain`). Saber escribir "1 ½" es la otra mitad de saber leerlo, que ya estaba en `Quantity::fromString()`. Si estuviera en el adaptador HTTP, la lista de la compra (bloque C) o un comando de Artisan tendrían que repetirlo.
- `GetRecipe`, `RecipeView`, `IngredientView` y `RecipeNotFound` están en la **aplicación** (`Recipes\Application`):
  - `GetRecipe` orquesta la consulta;
  - la vista es lo que la aplicación ofrece hacia fuera;
  - "no encontrado" es el resultado de **buscar**, y buscar es lo que hace un caso de uso a través del puerto (`find()` devuelve `null`). Ninguna regla del recetario dice "una receta tiene que existir".
- `Shared\Application\NotFound` está en el ***shared kernel* de la aplicación**: es la base común de cualquier "no encontrado" de cualquier contexto. Deliberadamente **no** hereda de `DomainException` (que significa "el negocio no lo permite" y da `422`).
- `GetRecipeController` y `RecipeResource` están en la **infraestructura** (`Recipes\Infrastructure\Http`): traducen HTTP ↔ caso de uso y deciden el formato JSON.
- El manejador de `NotFound` → `404` está en `bootstrap/app.php`, junto al de `DomainException` → `422`.
- `RecipeRepository` **no cambia**: la consulta reutiliza `find()`.

**Sentido de las dependencias:**

```
GetRecipeController ──► GetRecipe ──► RecipeRepository (Domain, puerto) ◄── EloquentRecipeRepository
        │                   │
        │                   └──► RecipeView::fromRecipe() ──► Recipe, Quantity::amountAsText() (Domain)
        └──► RecipeResource ──► RecipeView (Application)
bootstrap/app.php ──captura──► Shared\Application\NotFound (404) · Shared\Domain\DomainException (422)
```

Todas las flechas de código apuntan hacia dentro. El `RecipeResource` conoce la vista; la vista no sabe que existe JSON.

**Recorrido de una consulta de principio a fin:**

1. `GET /api/recipes/{id}` entra por la ruta con nombre `recipes.show`. No hay `FormRequest`: no existe un cuerpo que validar.
2. El contenedor construye `GetRecipeController` con `GetRecipe`, que recibe el adaptador de Eloquent por el *binding* de HEXM-0010.
3. `GetRecipe` convierte el texto en `RecipeId` (el dominio valida la forma y normaliza a minúsculas) y llama a `find()`.
4. El repositorio reconstruye la receta con `Recipe::create()` (ADR 0006).
5. `RecipeView::fromRecipe()` la convierte en primitivos: el nombre, las raciones, los pasos en orden y, por cada ingrediente, `amountAsText()` ("1 ½"), el código y el nombre de la unidad y si es al gusto.
6. El controlador envuelve la vista en `RecipeResource`, y Laravel responde `200 {"data": {...}}`.

Salidas alternativas:
- **3 bis:** si el id es `abc`, `RecipeId::fromString()` lanza `InvalidRecipeId` y el manejador de `DomainException` responde `422`.
- **4 bis:** si `find()` devuelve `null`, `GetRecipe` lanza `RecipeNotFound` y el manejador de `NotFound` responde `404`.

En ningún caso hay `try/catch` en el controlador.

**CQS, no CQRS.** `GetRecipe` es una consulta pura: devuelve datos y no cambia nada (un test lo comprueba). Pero comparte con el comando el mismo modelo y el mismo puerto. CQRS iría más lejos, con un modelo de lectura propio (por ejemplo, una consulta SQL que devuelva la vista sin reconstruir el agregado). Hoy no compensa: es una receta de una decena de filas. Queda anotado en el ADR para cuando haya listados o búsquedas.

**Por qué un id mal formado da `422` y no `404`.** No necesita código, porque el dominio ya lo rechaza con su mensaje en español, y distingue "no puede existir" de "no existe". La alternativa idiomática de Laravel, `whereUuid('id')` en la ruta, respondería un `404` en inglés antes de llegar al controlador. Un test de *feature* lo vigila: con `whereUuid()`, falla.

**Por qué un *API Resource* y no `JsonSerializable` en la vista.** Así el contrato JSON (claves en `snake_case`, el envoltorio `data`) queda en el adaptador HTTP. Un comando de Artisan podría formatear la misma vista a su manera. `JsonResource` envuelve aquí un DTO y no un modelo de Eloquent: a Laravel le da igual el tipo, solo necesita `toArray()`.

**De paso: la cabecera `Location` del alta.** Ahora se genera con `route('recipes.show', ...)`, a partir de la ruta con nombre, en vez de con una URL escrita a mano. Un test sigue la cabecera y comprueba que lleva a la receta.

## Alternativas descartadas

- **El Laravel clásico: `return new RecipeResource(Recipe::with('ingredients', 'steps')->findOrFail($id));`** en el controlador. Cabe en una línea, pero el controlador habla con Eloquent saltándose el caso de uso y el puerto. `findOrFail` da un 404 genérico en inglés, y el formato "1 ½" acabaría en el *resource* o en un *accessor* del modelo, lejos del `Quantity` que sabe leerlo.
- **Devolver la entidad `Recipe` desde `GetRecipe`.** Cualquier adaptador podría llamar a `rename()` sin pasar por un caso de uso, y nadie guardaría el cambio. Además, los adaptadores quedarían acoplados a la estructura interna del agregado.
- **Formatear "1 ½" en el adaptador HTTP**, con el DTO llevando numerador y denominador. El dominio no cambiaría, pero cada adaptador tendría que repetir el formato.
- **`RecipeNotFound` en el dominio, o heredando de `DomainException`.** Heredar daría `422` a algo que no es una regla incumplida. Ponerla en el dominio sugeriría una regla de negocio que no existe.
- **Convertir `InvalidRecipeId` en `RecipeNotFound` dentro de `GetRecipe`**, para responder `404` a todo. Más código, y se pierde la distinción entre "no puede existir" y "no existe".
- **`whereUuid('id')` en la ruta.** Es idiomático, pero da un `404` en inglés y se salta el mensaje del dominio.
- **Un objeto `GetRecipeQuery` como entrada**, por simetría con `CreateRecipeCommand`. Para un único campo es ceremonia: el caso de uso recibe el id como texto.
- **Un puerto de lectura propio (CQRS).** Ver el ADR 0008: se revisará cuando haya listados o búsquedas.

## Conceptos aplicados

| Concepto | Definición breve | Dónde verlo |
|---|---|---|
| CQS (separación entre comandos y consultas) | Un comando cambia el sistema; una consulta devuelve datos sin efectos secundarios. | `app-laravel/src/Recipes/Application/GetRecipe.php` (frente a `CreateRecipe.php`) |
| DTO de lectura (*read model* sencillo) | Una foto de solo lectura con primitivos; la entidad no sale de la aplicación. | `app-laravel/src/Recipes/Application/RecipeView.php`, `IngredientView.php` |
| "No encontrado" como contrato del caso de uso | Un resultado posible de la consulta, con su propia familia de excepciones. | `app-laravel/src/Shared/Application/NotFound.php`, `Recipes/Application/RecipeNotFound.php` |
| Conocimiento del negocio en el dominio | Escribir "1 ½" es la otra mitad de leerlo, y lo comparten todos los adaptadores. | `Quantity::amountAsText()` en `app-laravel/src/Recipes/Domain/Quantity.php` |
| Test de ida y vuelta (*round trip*) | Probar una propiedad: lo que se escribe se vuelve a leer igual. | `app-laravel/tests/Unit/Recipes/Domain/QuantityTest.php` |
| El formato de salida es del adaptador | El *API Resource* decide el JSON de una vista que no sabe nada de HTTP. | `app-laravel/src/Recipes/Infrastructure/Http/RecipeResource.php` |
| Traducción de excepciones en un único sitio | `NotFound` → 404 junto a `DomainException` → 422, capturando clases base. | `app-laravel/bootstrap/app.php` |
| Rutas con nombre | La URL se escribe una vez y se genera con `route()`. | `app-laravel/routes/api.php`, `CreateRecipeController.php` |
| `assertExactJson` como documentación ejecutable | El contrato completo de la respuesta, escrito como test. | `app-laravel/tests/Feature/Recipes/Infrastructure/Http/GetRecipeEndpointTest.php` |

## Diagramas

- [Consultar una receta](../diagramas/HEXM-0011/consultar-una-receta.sequence.json) (`sequence`): ¿qué recorrido hace `GET /api/recipes/{id}` y quién responde en cada uno de sus tres resultados (200, 404 y 422)?
- [Hexágono del recetario al cerrar el nivel 2](../diagramas/HEXM-0011/hexagono-recetario-nivel-2.architecture.json) (`architecture`): ¿cómo encajan los adaptadores HTTP, el comando y la consulta, el puerto y sus dos implementaciones al terminar el nivel?

Para verlos: `cd app-laravel && composer docs:diagrams` y abrir `docs/diagramas/HEXM-0011/consultar-una-receta.html` y `docs/diagramas/HEXM-0011/hexagono-recetario-nivel-2.html`.
