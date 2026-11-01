# Explicación HEXM-0007: Caso de uso CreateRecipe con repositorio en memoria

## Explicación

Tras HEXM-0005 y HEXM-0006, el dominio sabía qué es una receta válida, pero nadie podía "dar de alta una receta": faltaba quien recibiera los datos desde fuera, los convirtiera en objetos del dominio y guardara el resultado. Faltaba también decidir **dónde** se guarda, sin que el núcleo dependa de una base de datos que todavía no existe en el proyecto.

La solución es el primer recorrido hexagonal completo:

- un **caso de uso** (`CreateRecipe`) que orquesta;
- un **puerto de salida** (`RecipeRepository`), una interfaz que declara lo que el núcleo necesita;
- un **adaptador** (`InMemoryRecipeRepository`) que lo cumple guardando en memoria.

Todo funciona y se prueba sin Laravel ni PostgreSQL. En el nivel 2 se enchufará otro adaptador sin tocar nada de lo anterior.

## Lógica de negocio

- **Dar de alta una receta** es la primera acción del recetario. Por ejemplo: "Lentejas con pisto, 2 raciones: ½ bote de lentejas cocidas, 150 g de pisto, sal al gusto; pasos: calentar el pisto, añadir las lentejas escurridas".
- La receta se registra **solo si es válida**. Las reglas siguen siendo las del dominio (nombre, al menos un ingrediente, sin repetidos, al menos una ración, cantidades positivas). Si una falla, no queda nada a medias: el recetario no contiene recetas incompletas.
- Al darla de alta, la receta recibe su **identificador**, que es lo que se devuelve. Con él se podrá consultar después y, en el nivel 6, enlazarla desde un plato del plan ("Día 9 – comida").
- Los datos llegan **como los escribe una persona**: "½" y "jar" (bote), o nada en los dos campos para "al gusto". Un ingrediente con cantidad sin unidad ("200" a secas) o con una unidad que no existe ("gramo" en lugar de "gram") es un error que el usuario debe entender en español.

## Decisiones de arquitectura

```
 Adaptador de entrada          Aplicación                    Dominio
 (hoy: el test;                ──────────                    ───────
  nivel 2: controlador HTTP)
        │  CreateRecipeCommand
        │  (primitivos)
        └──────────────────►  CreateRecipe ───────────►  Recipe::create()  (valida)
                                   │
                                   │ save($recipe)
                                   ▼
                              RecipeRepository  ◄─── interfaz definida en el DOMINIO
                                   ▲
                                   │ implements
 Infraestructura          InMemoryRecipeRepository  (hoy) · EloquentRecipeRepository (nivel 2)
```

- **El puerto `RecipeRepository` vive en `Domain`, no en `Infrastructure`.** Es el núcleo quien dice qué necesita ("guárdame una receta"), con su propio lenguaje. La infraestructura importa la interfaz y la implementa. Así, aunque la llamada vaya hacia fuera (hacia el almacenamiento), la **dependencia del código apunta hacia dentro**: es la *inversión de dependencias*.
- **`CreateRecipe` está en `Application` y solo orquesta:** traduce los primitivos a value objects, delega en `Recipe::create()` todas las reglas (no hay ni un `if` de negocio) y guarda por el puerto. Recibe el puerto por el constructor (*inyección de dependencias*), así que el test le pasa el adaptador en memoria y Laravel le inyectará el de Eloquent con un *binding* en el nivel 2.
- **`CreateRecipeCommand` habla en primitivos** (string, int, arrays con forma conocida). Así cualquier adaptador de entrada puede construirlo sin conocer el dominio: un controlador, un comando de Artisan, el importador de Python del nivel 7 o un test.
- **El caso de uso no captura excepciones.** Las de dominio suben tal cual y el adaptador de entrada decide cómo mostrarlas (HTTP 422 en el nivel 2). Como `save()` solo se llama con una receta ya creada, es decir, válida, un error nunca deja nada guardado.
- **La traducción de primitivos sí está en el caso de uso, pero no las reglas.** Que "gramo" no sea una unidad o que falte la unidad son errores de traducción, y se expresan con excepciones de dominio (`InvalidIngredient::unknownUnit`, `incompleteQuantity`), nunca con el `ValueError` técnico de `Unit::from()`.
- **`InMemoryRecipeRepository` está en `Infrastructure`** aunque no use Laravel: es un detalle de almacenamiento. Guarda y devuelve copias (`clone`) para comportarse como una base de datos: si renombras una receta y no la guardas, lo almacenado no cambia. Su `count()` es solo una ayuda para los tests y no forma parte del puerto.
- **Los tests de arquitectura garantizan la frontera:** al inyectar a propósito `InMemoryRecipeRepository` en `CreateRecipe`, fallaron la regla "la aplicación no depende de infraestructura" y la lista blanca.

## Alternativas descartadas

- **Laravel clásico:** `RecipeController@store` con un `FormRequest` y `Recipe::create($request->validated())` sobre un modelo Eloquent con `hasMany`. La lógica queda atada a HTTP y a Eloquent: no se puede reutilizar desde Artisan o un importador sin duplicarla, y los tests necesitan arrancar el framework y la base de datos.
- **`nextIdentity()` en el repositorio:** es la forma clásica de DDD de obtener el id. Aquí el dominio ya genera la identidad con `RecipeId::generate()` (ADR 0005), y tener dos formas de hacer lo mismo obligaría a cada adaptador a implementarla.
- **Un *mock* del repositorio** (`shouldReceive('save')->once()`): comprueba que se llamó a `save()`, no que lo guardado sea correcto. Seguiría en verde aunque el caso de uso guardara una receta con el nombre equivocado, y se rompería al reorganizar el código aunque el resultado fuera el mismo. El fake en memoria comprueba el resultado y se reutiliza en todos los tests.
- **Que el caso de uso reciba objetos de dominio** (`Recipe` ya construida o value objects): obligaría a cada adaptador de entrada a conocer el dominio y repetir la traducción.
- **Guardar la misma instancia en el adaptador (sin `clone`):** los tests no detectarían un caso de uso que modifica una receta y olvida guardarla.
- **Que el caso de uso capture las excepciones y devuelva un "resultado con errores":** es más ceremonia sin necesidad; las excepciones de dominio ya llevan el mensaje para el usuario.

## Conceptos aplicados

| Concepto | Definición breve | Dónde verlo |
|---|---|---|
| Caso de uso (*application service*) | Orquesta un escenario del negocio; delega las reglas en el dominio y la persistencia en un puerto. | `src/Recipes/Application/CreateRecipe.php` |
| Puerto de salida (*driven port*) | Interfaz definida por el núcleo para lo que necesita del exterior. | `src/Recipes/Domain/RecipeRepository.php` |
| Adaptador (*driven adapter*) | Implementación concreta de un puerto. | `src/Recipes/Infrastructure/InMemoryRecipeRepository.php` |
| Inversión de dependencias | La infraestructura depende del dominio, aunque la llamada vaya hacia fuera. | `RecipeRepository.php` (cabecera) |
| Repositorio de agregados | Se guarda y se recupera la raíz entera; lenguaje de colección, no de tabla. | `RecipeRepository.php` |
| Comando / DTO de entrada | Objeto inmutable con primitivos que expresa una intención. | `src/Recipes/Application/CreateRecipeCommand.php` |
| Inyección de dependencias por constructor | El caso de uso recibe el puerto, nunca crea el adaptador. | `CreateRecipe::__construct()`, `CreateRecipeTest.php` (`beforeEach`) |
| Fake frente a mock | Doble de test que funciona de verdad y permite comprobar el resultado. | `InMemoryRecipeRepository.php`, `CreateRecipeTest.php` |
| Clase invocable y *first-class callable* | `__invoke` para un caso de uso de un solo método; `$this->toIngredient(...)` como closure. | `CreateRecipe.php` |
| Test de arquitectura como garantía | La frontera Application → Infrastructure está prohibida y comprobada. | `tests/Architecture/HexagonalLayersTest.php` |
