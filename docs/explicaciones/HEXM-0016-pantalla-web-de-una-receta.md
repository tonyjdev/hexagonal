# Explicación HEXM-0016: Pantalla web de una receta

## Explicación

Hasta esta tarea, la aplicación solo hablaba JSON: la única página visible era la bienvenida por defecto de Laravel. Esta tarea añade la **primera pantalla**: `GET /recipes/{id}` muestra una receta en el navegador.

Lo importante no es la pantalla en sí, sino **cómo se ha añadido**. La API ya tenía todo lo necesario para consultar una receta: el caso de uso `GetRecipe` y el DTO de lectura `RecipeView` (HEXM-0011). La web es simplemente **otro adaptador de entrada** que llama al mismo caso de uso y presenta el mismo DTO de otra manera:

```
GET /api/recipes/{id} ─► GetRecipeController ──┐                     ┌─► RecipeResource ─► JSON
                                               ├─► GetRecipe ─► RecipeView
GET /recipes/{id}     ─► RecipePageController ─┘                     └─► recipes::show  ─► HTML
```

La prueba de que la arquitectura hexagonal cumple su promesa es un criterio de aceptación: `git diff develop` sobre `src/Recipes/Domain` y `src/Recipes/Application` sale **vacío**. Añadir un canal no ha obligado a tocar ni el dominio ni la aplicación.

Por el camino se ha resuelto un pendiente de HEXM-0010 y HEXM-0011: qué ve una persona cuando algo falla. Hasta ahora, fuera de la API, una receta inexistente acababa en un error 500.

## Lógica de negocio

La pantalla no añade reglas de negocio nuevas: muestra las que ya existían.

- **Las cantidades se ven como en los planes.** El pisto muestra "½ · bote" y "1 ½ · cucharada sopera". El "1,5" que alguien escribió al crear la receta aparece como "1 ½", porque así lo escribe la nutricionista. Esto ya lo hacía el dominio desde HEXM-0011, y la pantalla solo lo muestra.
- **"Al gusto" se distingue.** La sal no tiene cantidad ni unidad: la fila dice "al gusto".
- **Los pasos son opcionales** (regla 12). Una receta sin pasos, como una ensalada que solo lista ingredientes, muestra "Esta receta no detalla pasos de preparación." en vez de una lista vacía.
- **Plural de las unidades.** En español se dice "200 gramos" y "½ bote", pero el recetario solo conoce el nombre en singular. Para no escribir "200 gramo", la pantalla usa una tabla con una columna "Unidad", donde la unidad funciona como un rótulo, y escribe "Raciones: 2". Pluralizar bien es conocimiento del negocio y queda pendiente para el dominio (`tech_debt` 223).
- **Si la receta no existe**, la persona ve "Página no encontrada" y "No existe ninguna receta con el identificador «…»". Si escribe una dirección sin sentido (`/recipes/abc`), ve "La página que buscas no existe".

## Decisiones de arquitectura

Las tres decisiones abiertas las tomó el usuario a partir de una recomendación razonada, y están en el [ADR 0009](../adr/0009-adaptador-web-vistas-en-el-contexto-y-errores-por-canal.md).

**Por qué cada pieza está en su capa:**

- `RecipePageController` está en `Recipes\Infrastructure\Http`, junto a `GetRecipeController`: los dos son adaptadores de entrada HTTP. Se diferencian en una línea, la que elige la presentación (`new RecipeResource(...)` o `view('recipes::show', ...)`).
- La vista `show.blade.php` está **dentro del contexto** (`src/Recipes/Infrastructure/Http/views`). Se registra con `loadViewsFrom(..., 'recipes')` en `RecipesServiceProvider::boot()` y se usa como `view('recipes::show')`. Así todo el recetario, también su HTML, se lee desde `src/Recipes`, igual que sus controladores y su repositorio. Es el mismo mecanismo que usan los paquetes de Laravel para traer sus vistas.
- El *layout* (`<x-layout>`) y la página `errors/404.blade.php` están en `resources/views`, porque son de **toda la aplicación**: los usarán también las pantallas de los planes.
- La traducción de errores sigue en un único sitio, `bootstrap/app.php`.
- `GetRecipe`, `RecipeView` y el dominio **no cambian**.

**Errores según el canal.** El caso de uso solo dice "no existe" (`RecipeNotFound`). Cómo se le cuenta eso a quien preguntó depende del canal, y lo decide el manejador global de `NotFound`:
- una petición JSON recibe `404 {"message": …}`, como en HEXM-0011;
- una petición web recibe la página `errors/404.blade.php` con el mensaje del caso de uso. Antes devolvía `null` y Laravel lo trataba como un error cualquiera: un 500.

La página 404 sirve para **cualquier** 404 de la web: una ruta que no existe, el `whereUuid` o un `NotFound`. Solo muestra el mensaje que le pasa explícitamente nuestro manejador. Los mensajes de las excepciones HTTP de Laravel son técnicos y están en inglés ("The route … could not be found"), así que en su lugar aparece un texto genérico en español.

**`whereUuid` en la web, no en la API.** En la API, un id mal formado responde `422` con el mensaje del dominio (ADR 0008): a un programa le interesa distinguir "esta receta no puede existir" de "no existe". A una persona que escribe una URL no le importa esa diferencia: la página no existe. Por eso la ruta web lleva `whereUuid('id')`, y Laravel responde 404 antes de llegar al controlador. Es la misma pregunta con respuestas distintas según quién la hace, y por eso se decide en el adaptador y no en el núcleo.

**Recorrido de una petición web de principio a fin:**

1. `GET /recipes/{id}` entra por `routes/web.php`. Si `{id}` no tiene forma de UUID, Laravel responde 404 con nuestra página genérica.
2. El contenedor construye `RecipePageController` con `GetRecipe`, y este con el adaptador de Eloquent (el *binding* de HEXM-0010).
3. `GetRecipe` busca la receta y devuelve un `RecipeView`, o lanza `RecipeNotFound`. En ese caso, el manejador global muestra la página 404 con el mensaje.
4. El controlador pasa el `RecipeView` a `view('recipes::show')`. La vista lo pinta dentro de `<x-layout>`, escapando todo el texto con `{{ }}`.

**Sin *pipeline* de frontend.** El CSS va en línea en el *layout*, sin npm ni Vite. La tarea solo necesita una página legible, y un sistema de compilación de recursos añadiría dependencias que no enseñan nada de arquitectura. Cuando llegue un frontend de verdad se decidirá con su propio ADR.

**Límite consciente.** Una `DomainException` en la web sigue terminando en 500. Hoy no puede ocurrir, porque la única página es de consulta y `whereUuid` filtra el id. Cuando haya formularios web, lo natural será volver al formulario con el mensaje del dominio (*redirect back with errors*).

## Alternativas descartadas

- **El Laravel clásico: un controlador en `app/Http/Controllers` con `Recipe::with('ingredients', 'steps')->findOrFail($id)`** y una vista en `resources/views/recipes/show.blade.php` que recorre el modelo de Eloquent. Es rápido, pero la vista queda atada a las tablas (tendría que convertir numerador y denominador en "1 ½" por su cuenta), duplica lo que ya hace la API y se salta el caso de uso. Aquí la web reutiliza exactamente el mismo núcleo que la API.
- **Las vistas en `resources/views`.** Es lo habitual y no necesita configuración, pero el recetario quedaría repartido entre `src/` y `resources/`. Solo lo que es de toda la aplicación (el *layout* y los errores) va allí.
- **Pluralizar en la vista** con una regla sencilla del español. Pone conocimiento del negocio en el adaptador y es fácil equivocarse ("cucharada sopera" → "cucharadas soperas").
- **Pluralizar en el dominio dentro de esta tarea.** Es lo correcto a largo plazo, pero rompía el criterio de aceptación de "núcleo sin cambios", que era el objetivo didáctico. Queda como `tech_debt`.
- **El mismo 422 de la API para `/recipes/abc` en la web.** Más coherente entre canales, pero un 422 es raro para una URL escrita en el navegador.
- **Livewire, Inertia o Tailwind.** Fuera de alcance: son decisiones de frontend que merecen su propio ADR.

## Conceptos aplicados

| Concepto | Definición breve | Dónde verlo |
|---|---|---|
| Varios adaptadores de entrada para un caso de uso | La web y la API llaman al mismo `GetRecipe` y presentan el mismo `RecipeView`. | `app-laravel/src/Recipes/Infrastructure/Http/RecipePageController.php` frente a `GetRecipeController.php` |
| La presentación es responsabilidad del adaptador | La vista solo lee datos ya calculados por el núcleo. | `app-laravel/src/Recipes/Infrastructure/Http/views/show.blade.php` |
| *Namespace* de vistas (`loadViewsFrom`) | Vistas fuera de `resources/views`, llamadas con un prefijo (`recipes::`). | `RecipesServiceProvider::boot()` |
| Componente Blade anónimo | Un *layout* reutilizable como etiqueta `<x-layout>`. | `app-laravel/resources/views/components/layout.blade.php` |
| El mismo error, distinta respuesta según el canal | Un `NotFound` es un 404 JSON en la API y una página 404 en la web. | `app-laravel/bootstrap/app.php`, `resources/views/errors/404.blade.php` |
| Restricciones de ruta (`whereUuid`) | Filtrar la forma de un parámetro antes de llegar al controlador. | `app-laravel/routes/web.php` |
| Escapado automático de Blade | `{{ }}` convierte el HTML en texto (protección frente a XSS). | `show.blade.php` y el test "escapes the recipe data" |
| Probar una presentación por lo que se ve | `assertSeeTextInOrder` en lugar de comparar el HTML exacto. | `app-laravel/tests/Feature/Recipes/Infrastructure/Http/RecipePageTest.php` |

## Diagramas

- [Dos canales, un caso de uso](../diagramas/HEXM-0016/dos-canales-un-caso-de-uso.architecture.json) (`architecture`): ¿cómo reutilizan la API y la web el mismo caso de uso y el mismo DTO, y dónde se separan?
- [Errores según el canal](../diagramas/HEXM-0016/errores-segun-el-canal.workflow.json) (`workflow`): ¿qué ve el cliente al pedir una receta que existe, que no existe o con un id mal formado, en la web y en la API?

Para verlos: `cd app-laravel && composer docs:diagrams` y abrir `docs/diagramas/HEXM-0016/dos-canales-un-caso-de-uso.html` y `docs/diagramas/HEXM-0016/errores-segun-el-canal.html`.
