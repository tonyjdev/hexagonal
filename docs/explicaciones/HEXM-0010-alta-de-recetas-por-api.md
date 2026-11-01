# Explicación HEXM-0010: Alta de recetas por API

## Explicación

Hasta ahora, solo los tests podían crear recetas: llamaban a `CreateRecipe` directamente. Esta tarea abre la primera **puerta de entrada** real a la aplicación, `POST /api/recipes`. Con ella el hexágono queda cerrado de punta a punta por primera vez:

```
Cliente HTTP ─► adaptador driving ─► caso de uso ─► dominio ─► puerto ─► adaptador driven ─► PostgreSQL
               (controlador)         (CreateRecipe)  (Recipe)   (RecipeRepository) (Eloquent, HEXM-0009)
```

Igual que en HEXM-0009, la prueba de fuego es que **`CreateRecipe` no cambia ni una línea**: el caso de uso que ya funcionaba con el adaptador en memoria funciona ahora detrás de HTTP y con PostgreSQL.

La idea de la solución se resume en tres piezas, cada una con una sola responsabilidad:

- un **controlador** que solo traduce: petición → comando → caso de uso → respuesta `201`;
- una **validación de forma** en la frontera, que comprueba que la petición cumple el contrato de la API y **deja las reglas de negocio al dominio**;
- un **único manejador** que convierte cualquier error de dominio en un `422` con su mensaje en español.

A esto se suma el *binding* del puerto a su adaptador en un *service provider*, para que el contenedor de Laravel sepa construirlo todo.

## Lógica de negocio

Las reglas son las mismas que ya protegía el dominio (reglas 8 a 12 de `docs/DOMINIO.md`). Lo nuevo es **cómo llegan a quien está fuera**.

Un ejemplo con el *pisto* de los planes: 2 raciones, un calabacín, ½ bote de tomate triturado, sal al gusto y dos pasos.

- **Una receta válida se guarda tal cual.** El ½ bote sigue siendo exactamente ½ y la sal sigue siendo "al gusto". Quien la envía recibe el identificador de la receta y la dirección donde consultarla.
- **Una receta que el negocio no permite se rechaza con un mensaje que la persona entiende**, sin guardar nada:
  - sin ingredientes: "Una receta debe tener al menos un ingrediente.";
  - con 0 raciones: "Una receta debe tener al menos una ración.";
  - con "0 calabacines": "La cantidad debe ser mayor que cero.";
  - con el calabacín dos veces: "El ingrediente «Calabacín» está repetido.";
  - con "un puñado" como unidad: "La unidad "handful" no existe.".
- **Una petición mal construida es otra cosa.** Si falta el número de raciones, o pone "dos" con letras, o las cantidades llegan como número en vez de como texto, no es que el negocio lo prohíba: es que **la ficha no está bien rellenada**. Se rechaza antes de mirar ninguna regla, señalando qué casilla está mal.

La metáfora que se usó durante la tarea: un **recepcionista** (la frontera) comprueba que la ficha esté bien rellenada, y un **chef** (el dominio) decide si la receta tiene sentido. El recepcionista no sabe de cocina, y el chef no repasa casillas.

## Decisiones de arquitectura

**Por qué cada pieza está en su capa:**

- `CreateRecipeController` y `CreateRecipeRequest` viven en `Recipes\Infrastructure\Http`. Son piezas de HTTP: conocen Laravel y el núcleo, pero el núcleo no las conoce. No están en `app/Http` porque, como el repositorio de Eloquent, pertenecen a su *bounded context*: todo el recetario se lee desde `src/Recipes`.
- `RecipesServiceProvider` vive en `Recipes\Infrastructure`. Es la **raíz de composición** del contexto, el único sitio donde aparecen juntos el puerto `RecipeRepository` y su adaptador `EloquentRecipeRepository`. Por eso tiene que estar fuera del núcleo.
- El manejador de `DomainException` está en `bootstrap/app.php` (`withExceptions()`), el sitio que Laravel 13 dedica a configurar las excepciones. Captura la **clase base del *shared kernel***, así que no conoce ninguna excepción concreta de ningún contexto.
- `CreateRecipe`, `Recipe` y el puerto **no cambian**.

Dos tests de arquitectura nuevos convierten esto en reglas comprobables:
- `Illuminate\Http`, `Illuminate\Foundation\Http` e `Illuminate\Routing` solo pueden usarse en `App` o en las capas `Infrastructure`;
- `Illuminate\Support\ServiceProvider`, lo mismo.

Se comprobó que fallan con un controlador puesto en `Application` y con un *provider* puesto en `Domain`.

**Sentido de las dependencias:**

```
CreateRecipeController ──► CreateRecipe (Application) ──► RecipeRepository (Domain, puerto)
        │                                                          ▲
        └──► CreateRecipeRequest ──► CreateRecipeCommand           │ implements
                                                                   │
RecipesServiceProvider ──bind──► RecipeRepository → EloquentRecipeRepository
bootstrap/app.php ──captura──► Shared\Domain\DomainException
```

Todas las flechas de código apuntan hacia dentro. El núcleo no sabe que existe HTTP: el mismo `CreateRecipe` lo podrá llamar mañana un comando de Artisan o el importador de Python.

**Recorrido de una petición válida de principio a fin:**

1. `POST /api/recipes` entra por `routes/api.php`, registrado con `api:` en `withRouting()`. Eso le da el prefijo `/api` y el middleware sin estado.
2. Laravel construye el controlador con su contenedor. Para eso necesita `CreateRecipe`, y `CreateRecipe` necesita un `RecipeRepository`. El *binding* del *provider* responde con `EloquentRecipeRepository` (*autowiring*).
3. Antes de ejecutar el controlador, Laravel valida la `CreateRecipeRequest`, es decir, la forma. Si falla, responde él mismo `422 {"message", "errors"}` y el recorrido termina aquí.
4. El controlador pide a la `FormRequest` el `CreateRecipeCommand` (`toCommand()`) e invoca el caso de uso.
5. `CreateRecipe` traduce los primitivos a objetos del dominio. `Recipe::create()` comprueba las invariantes y el repositorio guarda la receta en una transacción.
6. El controlador responde `201 {"id": "…"}` con la cabecera `Location: /api/recipes/{id}`.

Si en el paso 5 el dominio lanza una `DomainException`, sube sin `try/catch` hasta el manejador global, que responde `422 {"message": "…"}`. Como `save()` solo se llama con una receta ya creada, no se guarda nada.

**La frontera valida la forma; el dominio, las reglas.** Es la decisión central de la tarea ([ADR 0007](../adr/0007-adaptador-http-validacion-de-forma-y-errores-de-dominio.md)). En la `FormRequest`:
- no hay `min:1` en `servings` ni `in:gram,jar…` en `unit`;
- `ingredients` usa `present` (el campo debe existir) en vez de `required`, que rechazaría la lista vacía.

Así, cada regla de negocio se escribe una sola vez, en el dominio, y protege a todos los adaptadores de entrada. Tres tests de mutación lo vigilan: si alguien añade "por si acaso" un `min:1`, falla el test de "0 raciones", porque el mensaje ya no sería el del dominio.

**El detalle de `ConvertEmptyStringsToNull`.** Laravel convierte cada `""` de la petición en `null` antes de validarla. Si se dejara así, un nombre vacío llegaría como `null` a un caso de uso que espera `string`, y el resultado sería un error técnico (500). `toCommand()` lo devuelve a `""` en los textos, para que sea el dominio quien diga "Una receta debe tener nombre". En `amount` y `unit`, en cambio, `null` significa "al gusto" y se deja tal cual.

**`bind` y no `singleton`.** El repositorio no tiene estado y cualquiera de los dos funcionaría. `bind` es la opción segura por defecto: si el adaptador llegara a tener estado, un `singleton` lo compartiría entre peticiones en servidores que reutilizan el proceso, como Octane.

**Por qué 422 para los errores de dominio, y por qué no se registran en el log.** La petición está bien formada, pero su contenido incumple una regla: no es un 400 (petición mal construida) ni un 500 (fallo nuestro). Un test comprueba que un error técnico sigue siendo 500. Si se convirtiera en 422, se culparía al cliente de un fallo del servidor. Los errores de dominio son errores esperados del usuario, así que `dontReport` evita llenar el log con ellos, igual que Laravel hace con sus `ValidationException`.

## Alternativas descartadas

- **El Laravel clásico: `Recipe::create($request->validated())` en el controlador**, con todas las reglas en la validación (`required|min:1`, `in:gram,jar,…`). Funciona, pero las reglas de negocio quedan atadas a HTTP. El comando de Artisan o el importador de Python tendrían que repetirlas, y con el tiempo divergirían. Además, el controlador hablaría directamente con Eloquent, saltándose el caso de uso y el puerto.
- **Duplicar las reglas en la `FormRequest` "para fallar antes".** Se descartó por lo mismo: dos fuentes de verdad. El coste es que una petición puede fallar en dos momentos (primero la forma, después las reglas), y queda registrado en el ADR.
- **Validar con `$request->validate()` dentro del controlador.** Es equivalente, pero mezcla validación y orquestación. La `FormRequest` separa la forma y además concentra la traducción al comando en `toCommand()`.
- **`try/catch` en cada controlador** para convertir los errores de dominio. Se repetiría en todos los controladores y sería fácil olvidarlo en uno. El manejador global lo hace una vez, para toda la aplicación.
- **Registrar el *binding* en `AppServiceProvider`.** Mezclaría los adaptadores de todos los contextos en un fichero de `app/`. Un *provider* por contexto mantiene el recetario autocontenido.
- **`php artisan install:api`.** Habría instalado Sanctum (autenticación), que está fuera del alcance. Las rutas de API se registran a mano.
- **Aceptar `amount` también como número.** Es razonable (un cliente JSON enviaría `200`), pero el contrato actual pide texto porque admite "½" o "1,5". Si se decide cambiarlo, solo se toca la `FormRequest`: ni el caso de uso ni el dominio se enteran.

## Conceptos aplicados

| Concepto | Definición breve | Dónde verlo |
|---|---|---|
| Adaptador *driving* (de entrada) | Traduce un protocolo externo (HTTP) al lenguaje del núcleo (un comando) y pone en marcha un caso de uso. | `app-laravel/src/Recipes/Infrastructure/Http/CreateRecipeController.php` |
| Raíz de composición | El único sitio donde se decide qué adaptador implementa cada puerto. | `app-laravel/src/Recipes/Infrastructure/RecipesServiceProvider.php` |
| *Binding* y *autowiring* | El contenedor de Laravel resuelve una interfaz con la implementación registrada y construye las clases concretas leyendo su constructor. | `RecipesServiceProvider::register()` y `tests/Feature/Recipes/Infrastructure/RecipesServiceProviderTest.php` |
| Validación de forma frente a reglas de negocio | La frontera comprueba el contrato (campos y tipos); el dominio, lo que el negocio permite. | `app-laravel/src/Recipes/Infrastructure/Http/CreateRecipeRequest.php` |
| *Form Request* | Clase de Laravel que valida la petición antes de ejecutar el controlador. | `CreateRecipeRequest::rules()` |
| Anticorrupción en pequeño | La traducción del JSON de la API al comando de la aplicación, en un solo método. | `CreateRecipeRequest::toCommand()` |
| Traducción de excepciones en un único sitio | La clase base del *shared kernel* se convierte en HTTP 422 en un manejador global. | `app-laravel/bootstrap/app.php` (`withExceptions()`) |
| Controlador invocable | Una acción por clase, con `__invoke`, igual que los casos de uso. | `CreateRecipeController` |
| Test de *feature* de punta a punta | Petición HTTP simulada que recorre la aplicación completa hasta PostgreSQL. | `app-laravel/tests/Feature/Recipes/Infrastructure/Http/CreateRecipeEndpointTest.php` |
| Tests de mutación (manuales) | Estropear a propósito una decisión para comprobar que algún test lo detecta. | Documento de Pruebas de HEXM-0010 |
| Tests de arquitectura (*fitness functions*) | HTTP y los *service providers* solo pueden vivir en `Infrastructure` o en `App`. | `app-laravel/tests/Architecture/HexagonalLayersTest.php` |

## Diagramas

- [Alta de una receta por HTTP](../diagramas/HEXM-0010/alta-de-receta-por-http.sequence.json) (`sequence`): ¿qué recorrido hace `POST /api/recipes` en cada uno de sus tres caminos (201, 422 de validación y 422 del dominio) y quién responde en cada caso?
- [Forma frente a reglas](../diagramas/HEXM-0010/forma-frente-a-reglas.workflow.json) (`workflow`): ¿quién rechaza una petición, la frontera HTTP o el dominio, y cómo se distinguen los dos 422?

Para verlos: `cd app-laravel && composer docs:diagrams` y abrir `docs/diagramas/HEXM-0010/alta-de-receta-por-http.html` y `docs/diagramas/HEXM-0010/forma-frente-a-reglas.html`.
