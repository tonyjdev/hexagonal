# Explicación HEXM-0005: Value objects del recetario

## Explicación

Una receta está hecha de piezas pequeñas: cantidades ("½ bote"), unidades ("cucharada sopera"), raciones ("para 4") y pasos ("sofreír la cebolla"). Lo cómodo sería representarlas con tipos primitivos: un `float` para la cantidad, un `string` para la unidad, un `int` para las raciones. El problema es que un `float` admite `0` o `-3`, un `string` admite `"gramo"`, `"gr"` y `"g"`, y un `int` admite cero raciones. Con primitivos, cada regla del negocio habría que volver a comprobarla en cada sitio que recibe el dato, y bastaría con olvidarse una vez para que entrara un dato imposible.

La idea de la solución es darle a cada concepto **su propio tipo**, que se valida al crearse y no puede cambiar después (*value object*). Si tienes una `Quantity`, es válida: no hace falta volver a mirarla. Con ello aparecen tres decisiones que el negocio obligaba a tomar: cómo representar ½, ⅓ y ¾ sin errores de redondeo, qué hacer con "al gusto", y quién da identidad a una receta.

## Lógica de negocio

- **Las cantidades de los planes son fracciones.** La semana 1 de la nutricionista dice "Lentejas (½ bote) con pisto" y en las recetas aparecen "⅓ de vaso" o "¾ de taza". Una cantidad tiene que poder escribirse como la escribe una persona ("½", "1 ½", "1/2", "1,5") y guardarse **exactamente**: en el bloque C se sumarán cantidades para la lista de la compra (tres recetas con ⅓ de vaso de caldo son exactamente 1 vaso, no 0,999).
- **Una cantidad siempre es positiva.** "0 gramos de arroz" o "-2 huevos" no tienen sentido; si aparecen, es un error de quien introduce la receta, y debe recibir un mensaje claro en español.
- **"Al gusto" y "una pizca" no son cantidades.** "Sal al gusto" dice que el ingrediente lleva sal, pero no cuánta. No hay número que guardar, así que no es una cantidad rara, sino la ausencia de cantidad. Para la lista de la compra significa "comprobar que hay sal", no "comprar X gramos".
- **Las unidades son un vocabulario cerrado**: gramo, mililitro, unidad, cucharada sopera, cucharadita, vaso, loncha, filete, lata y bote, las que aparecen en los planes reales. "Cucharada" y "cucharadita" no son lo mismo, y no se puede escribir una unidad inventada.
- **Una receta da al menos una ración** y **cada paso de preparación dice algo**: un paso vacío no es un paso.
- **Una receta tiene identidad desde que nace.** "La crema de calabaza" sigue siendo la misma receta aunque le cambies el nombre a "crema de calabaza asada". Más adelante (nivel 6), un plato del plan ("Día 9 – comida: crema de calabaza") podrá apuntar a esa receta, y lo hará por su identificador.

## Decisiones de arquitectura

Todo lo creado está en la capa de **dominio**: son las reglas del negocio y no dependen de nada externo. No hay puertos ni adaptadores en esta tarea; el dominio todavía no habla con nadie.

```
Shared\Domain                       Recipes\Domain
─────────────                       ──────────────
DomainException (abstracta) ◄────── InvalidQuantity, InvalidServings,
                                    InvalidStep, InvalidRecipeId
                                          ▲ lanzadas por
                                    Quantity ──► Unit (enum)
                                    Servings · Step · RecipeId

Dependencias: solo hacia Shared\Domain y PHP nativo (lista blanca, ADR 0004).
```

- **`DomainException` en `Shared\Domain`**: es la base de todos los errores de negocio de cualquier contexto. Vive en el *shared kernel* porque `MealPlanning` también la necesitará, y porque así, en el nivel 2, **un único** manejador de excepciones de Laravel (infraestructura) podrá convertir cualquier error de dominio en un HTTP 422 sin conocer cada excepción concreta. El dominio lanza; la infraestructura decide cómo se muestra. Es abstracta porque nunca se lanza "un error" genérico, sino uno concreto (`InvalidQuantity::notPositive()`).
- **Excepciones concretas en `Recipes\Domain`**, cada una con constructores con nombre: el mensaje en español se escribe en un solo sitio.
- **Constructor privado + constructores con nombre** en cada value object: la única forma de obtener un objeto es pasando por la validación. Así se consigue un *always-valid domain*.
- **El dominio genera el `RecipeId`** (ADR 0005). La entidad nace con identidad, se puede probar en memoria y el repositorio (HEXM-0007 y nivel 2) solo la guarda. Se generan UUID v7, ordenados por tiempo, con PHP nativo, para no tener que autorizar una librería en la lista blanca.
- **Los tests de arquitectura vigilan ya código real**: se comprobó introduciendo a propósito `Illuminate\Support\Str` en `Step`, y fallaron las reglas de lista negra y de lista blanca.

Recorrido de un dato (cuando existan los adaptadores, nivel 2):

```
POST /api/recipes {"amount": "½", "unit": "jar"}
  → Controlador (Infrastructure) → CreateRecipe (Application)
  → Quantity::fromString('½', Unit::from('jar'))   (Domain: valida o lanza)
  → InvalidQuantity ─► manejador de Laravel ─► 422 con el mensaje en español
```

## Alternativas descartadas

- **Laravel clásico: columnas `float` y validación en un `FormRequest`.** La validación viviría en la frontera HTTP y no en el concepto. Un comando de Artisan, un importador o un test podrían crear una cantidad de cero sin pasar por el `FormRequest`. Además, `float` no representa ⅓ y arrastra errores (en PHP, `0.1 + 0.2 === 0.3` es `false`).
- **Cast de Eloquent o `Attribute` en el modelo**: ataría el concepto "cantidad" a Eloquent y a la base de datos, y el dominio no podría usarlo sin Laravel.
- **String decimal con `bcmath`**: es exacto para decimales, pero no para ⅓ (0,333…), así que no cumplía el criterio de aceptación.
- **`Unit::ToTaste` para "al gusto"**: obligaría a `Quantity` a admitir "sin número" como caso especial y rompería su invariante más simple ("siempre hay un número mayor que cero").
- **Clave autoincremental de la base de datos**: la receta no tendría identidad hasta guardarse, y el dominio dependería de PostgreSQL para algo básico.
- **`ramsey/uuid` o `symfony/uid`**: son librerías sólidas, pero exigían autorizar la primera dependencia externa del núcleo por unas 15 líneas de PHP nativo. Se reconsiderará si el generador se complica.
- **UUID v4**: más sencillo, pero completamente aleatorio. En PostgreSQL inserta en posiciones aleatorias del índice; v7 crece por el final.
- **Extraer ya un `Uuid` común a `Shared\Domain`**: hoy solo lo usa `RecipeId`. Se extraerá cuando `MealPlanning` necesite identificadores (nivel 3), para no crear una abstracción compartida con un único uso.

## Conceptos aplicados

| Concepto | Definición breve | Dónde verlo |
|---|---|---|
| Value object | Sin identidad, inmutable y comparado por valor con `equals()` (no con `===`, que compara si es el mismo objeto en memoria). | `src/Recipes/Domain/Quantity.php`, `Servings.php`, `Step.php` |
| *Always-valid domain* | La validación está en la creación: un objeto inválido no puede existir. | Constructores privados de todos los value objects |
| Constructores con nombre | Métodos estáticos que dicen cómo se crea el objeto: `of`, `fraction`, `fromString`, `generate`. | `Quantity.php`, `RecipeId.php` |
| *Primitive obsession* | Abusar de `int` o `string` para conceptos del negocio; se evita con tipos propios. | `Servings.php` |
| Enum respaldado | Conjunto cerrado de valores, con valor persistible en inglés y etiqueta en español. | `src/Recipes/Domain/Unit.php` |
| Aritmética exacta | Fracción como numerador/denominador simplificados por el MCD (algoritmo de Euclides). | `Quantity::fraction()` |
| Identidad generada por el dominio | UUID v7: marca de tiempo + aleatorio, ordenado por fecha. | `RecipeId::generate()`, ADR 0005 |
| Excepción de dominio y *shared kernel* | Errores de negocio en el lenguaje del usuario, con una base común a todos los contextos. | `src/Shared/Domain/DomainException.php` |
| Tests de dominio sin framework | Pest sin arrancar Laravel; datasets para recorrer casos. | `tests/Unit/Recipes/Domain/` |
