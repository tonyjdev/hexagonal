# Explicación HEXM-0006: Entidad Recipe e ingredientes

## Explicación

Con los value objects de HEXM-0005 (cantidades, unidades, raciones, pasos, identificador) ya se podían describir las piezas de una receta, pero no la receta. Faltaba el objeto que las reúne y, sobre todo, que **hace cumplir las reglas que afectan al conjunto**: que no falten ingredientes o que no se repita ninguno. Esas reglas no se pueden comprobar mirando un ingrediente suelto; solo tienen sentido mirando la receta entera.

La solución es una **entidad** `Recipe`, raíz de un **agregado** que contiene sus ingredientes y pasos. Toda receta se crea y se modifica a través de ella, y ella comprueba sus invariantes cada vez. Así, cualquier `Recipe` que exista en memoria es una receta válida.

## Lógica de negocio

- **Una receta tiene nombre y al menos un ingrediente.** "Lentejas con pisto" sin ingredientes no es una receta. Un ingrediente "al gusto" (la sal) también cuenta: una receta de "aliño" puede llevar solo ingredientes al gusto.
- **No se repite un ingrediente.** Si una receta lleva "Cebolla (1 unidad)" y más abajo "cebolla (½ unidad)", es un error de quien la escribe: es la misma cebolla. Se compara el nombre sin distinguir mayúsculas ni espacios. Esta regla prepara la lista de la compra del bloque C: cada ingrediente aparece una vez por receta, así que sumar cantidades entre recetas no tendrá ambigüedad.
- **Los pasos son opcionales.** En los planes, el pisto de la semana 1 detalla ingredientes y raciones, pero no cómo se hace. Debe poder registrarse igualmente y completar los pasos después. Cuando hay pasos, su orden importa: "calentar el pisto" va antes que "añadir las lentejas".
- **Una receta sigue siendo la misma aunque cambie.** Si "Crema de calabaza" se renombra a "Crema de calabaza asada", es la misma receta. Cuando en el nivel 6 un plato del plan apunte a ella ("Día 9 – cena"), el enlace no se rompe.
- **Un ingrediente no tiene identidad.** "200 g de arroz" en una receta es igual a "200 g de arroz" en otra. No hay "un arroz concreto" que seguir en el tiempo.

## Decisiones de arquitectura

Todo sigue en la capa de **dominio** (`Recipes\Domain`): son reglas del negocio, sin puertos ni adaptadores todavía. El repositorio que guardará la receta llega en HEXM-0007.

```
              ┌──────────── Agregado Recipe (frontera de consistencia) ─────────────┐
 fuera  ───►  │  Recipe (raíz, entidad)                                             │
 (casos       │    · RecipeId  · nombre  · Servings                                 │
  de uso)     │    · list<Ingredient> ──► Ingredient (VO) ──► ?Quantity ──► Unit    │
              │    · list<Step>       ──► Step (VO)                                 │
              └─────────────────────────────────────────────────────────────────────┘
   Solo se entra por Recipe::create() y rename(); las listas salen como copias.
```

- **Por qué `Recipe` es una entidad y `Ingredient` un value object.** La receta tiene identidad y ciclo de vida, así que se compara por `RecipeId`. El ingrediente no tiene vida fuera de su receta: se compara por valor y no tiene id. Si cambia la cantidad de un ingrediente, la receta tiene *otro* ingrediente; no se "edita" el anterior.
- **Por qué un agregado.** Las reglas "al menos un ingrediente" y "sin repetidos" afectan a la colección entera. Si los ingredientes se pudieran añadir por fuera de la receta (como con una relación `hasMany` de Eloquent: `$recipe->ingredients()->create(...)`), nadie garantizaría las reglas. La raíz es la única puerta, y el repositorio (HEXM-0007) guardará y cargará siempre la receta completa. El agregado marca también la unidad de transacción.
- **Por qué no hay setters (modelo rico).** `rename()` expresa una intención del negocio y valida antes de cambiar; si el nombre es inválido, la receta queda intacta. Un `setName()` genérico invita a dejar el objeto inválido "un momento" y validarlo después en otro sitio. Solo existe el comportamiento que alguien necesita hoy: crear y renombrar.
- **Cómo se protege el estado interno sin `Collection`.** El dominio no puede usar `Illuminate\Support\Collection` (lista blanca, ADR 0004). Tampoco hace falta: los arrays de PHP se copian por valor, así que modificar la lista devuelta o la lista con la que se creó la receta no afecta a la receta. Las propiedades son `readonly` y los elementos son value objects inmutables. PHPStan comprueba los tipos `list<Ingredient>` de los docblocks.
- **Recorrido futuro** (HEXM-0007 y nivel 2): `POST /api/recipes` → controlador (Infrastructure) → `CreateRecipe` (Application) → `Recipe::create()` (Domain: valida o lanza `InvalidRecipe`) → `RecipeRepository::save()` (puerto) → adaptador en memoria o Eloquent.
- Se declara `ext-mbstring` en `composer.json`: el dominio usa `mb_strtolower` para comparar nombres con tildes y eñes ("Ñora" y "ñora"). Es una extensión de PHP, no una librería, así que no entra en la lista blanca, pero el proyecto debe declararla como requisito.

## Alternativas descartadas

- **Laravel clásico: modelo Eloquent `Recipe` con `hasMany(Ingredient::class)`** y validación en un `FormRequest` (`'ingredients' => 'required|array|min:1'`). Las reglas vivirían en la frontera HTTP: un importador, un comando o un seeder podrían crear recetas sin ingredientes. Además, el modelo sería anémico (atributos públicos mágicos) y los ingredientes se podrían crear por su cuenta, saltándose la receta.
- **`Ingredient` como entidad con `IngredientId`.** Es lo que sugiere una tabla `ingredients` con su `id`. El negocio no lo necesita (nadie sigue "un ingrediente" en el tiempo), y añadiría identidad y comparaciones por id sin motivo. Cómo se guarde en la base de datos es asunto del adaptador del nivel 2.
- **Ingredientes repetidos permitidos**, o fusionados sumando cantidades: lo primero complicaría la lista de la compra; lo segundo añade lógica (sumar fracciones de distintas unidades) que hoy nadie pide.
- **Pasos obligatorios:** impediría registrar recetas de los planes que solo detallan ingredientes.
- **Una clase `IngredientList` propia** (colección tipada): da más control, pero hoy un array con `list<Ingredient>` y PHPStan basta. Se reconsiderará si aparecen operaciones sobre la colección (buscar, sustituir, sumar).
- **`replaceIngredients()` y otros métodos de modificación:** fuera de alcance porque `CreateRecipe` no los necesita. Añadirlos "por si acaso" sería código sin uso que mantener.

## Conceptos aplicados

| Concepto | Definición breve | Dónde verlo |
|---|---|---|
| Entidad | Objeto con identidad y ciclo de vida; se compara por su id. | `src/Recipes/Domain/Recipe.php` (`equals()`, `rename()`) |
| Value object | Sin identidad, inmutable, comparado por valor. | `src/Recipes/Domain/Ingredient.php` |
| Agregado y raíz de agregado | Grupo de objetos que se valida y se persiste como una unidad; solo se modifica a través de su raíz. | `Recipe.php` (cabecera) |
| Invariante | Regla que el objeto cumple siempre, desde que se crea. | `Recipe::create()`, `guardAgainst…()` |
| Modelo rico frente a anémico | Métodos con significado de negocio en vez de setters. | `Recipe::rename()` |
| Colecciones inmutables con arrays | Copia por valor (*copy-on-write*), propiedades `readonly` y tipos `list<T>` para PHPStan. | `Recipe.php`, `RecipeTest.php` ("protected internal state") |
| Tipo nullable | `?Quantity` obliga a tratar el caso "al gusto". | `Ingredient::quantity()` |
| Identidad frente a valor en tests | El mismo id con otros datos es la misma receta; los mismos datos con otro id son otra receta; los mismos datos son el mismo ingrediente. | `RecipeTest.php` ("identity"), `IngredientTest.php` |
