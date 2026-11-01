# 0006. Persistencia del agregado Recipe con Eloquent

**Fecha:** 2026-11-01 · **Estado:** aceptada · **Tarea:** HEXM-0009 · **Relacionado:** [ADR 0003](0003-arquitectura-hexagonal-en-src.md), [ADR 0005](0005-identificadores-uuid-v7-en-el-dominio.md)

## Contexto

El puerto `RecipeRepository` solo tenía un adaptador en memoria (HEXM-0007). Para guardar las recetas en PostgreSQL había que decidir:

1. **Dónde viven los modelos de Eloquent** y qué relación tienen con la entidad `Recipe`.
2. **Cómo se reparte el agregado en tablas**: una receta tiene ingredientes con una cantidad exacta (fracción) u opcional ("al gusto") y pasos ordenados, y ninguno de los dos tiene identidad propia.
3. **Cómo se reconstruye la receta al leerla**: con `Recipe::create()`, que comprueba las invariantes, o con un constructor de reconstitución que se las salte.
4. **Cómo se guarda una receta que ya existía**, sin dejar datos a medias si algo falla.

Condición de partida: el dominio y la aplicación no se tocan (criterio de aceptación de HEXM-0009).

## Decisión

1. **Los modelos de Eloquent son un detalle del adaptador.** `RecipeModel`, `RecipeIngredientModel` y `RecipeStepModel` viven en `Recipes\Infrastructure\Persistence\Eloquent` (no en `app/Models`) y nunca salen de ahí. Un `RecipeMapper` (patrón *Data Mapper*) traduce entre el agregado y las filas. Un test de arquitectura nuevo comprueba que `Illuminate\Database\Eloquent` solo se usa en las capas `Infrastructure`, en `app/` y en `database/`.
2. **Tres tablas para un agregado:**
   - `recipes`: `id uuid` como clave primaria (el id lo genera el dominio, ADR 0005), `name`, `servings` y las marcas de tiempo, que son operativas y no del dominio.
   - `recipe_ingredients` y `recipe_steps`: **clave primaria compuesta `(recipe_id, position)`**, sin `id` propio, porque `Ingredient` y `Step` son *value objects*. `position` conserva el orden de la lista. Las claves foráneas usan `ON DELETE CASCADE`.
   - La cantidad se guarda en `numerator`, `denominator` y `unit`. Las tres son `NULL` para "al gusto" y la fracción se guarda exacta (½ = 1/2). Un `CHECK` de PostgreSQL obliga a que las tres sean nulas o las tres válidas. Cada columna se exige con `IS NOT NULL` explícito: en SQL, `NULL > 0` no da `false` sino `NULL`, y un `CHECK` deja pasar lo que da `NULL`. La primera versión no lo tenía en cuenta y aceptaba `(1, NULL, 'jar')`, hasta que lo destapó la prueba manual. La unidad se guarda como texto (el valor del enum `Unit`) y no como un `ENUM` de PostgreSQL, para que añadir una unidad no requiera migrar el tipo.
3. **Reconstitución con los constructores normales del dominio** (`Recipe::create()`, `Ingredient::of()`/`toTaste()`, `Quantity::fraction()`, `Step::fromString()`…). No se añade `Recipe::reconstitute()`.
4. **`save()` reemplaza el agregado entero en una transacción**:
   - `updateOrCreate` de la fila de `recipes`;
   - borrado de los ingredientes y pasos anteriores;
   - inserción en bloque de los actuales.

   La transacción se abre en la conexión del propio modelo. `find()` carga la receta con sus ingredientes y pasos en tres consultas (*eager loading*).
5. **Tests de contrato del puerto**: una misma batería se ejecuta contra `InMemoryRecipeRepository` y `EloquentRecipeRepository` mediante un *dataset* de Pest. Sustituye al test específico del adaptador en memoria.

## Consecuencias

- **A favor:**
  - La base de datos entró en el proyecto sin modificar `Recipe`, `CreateRecipe` ni el puerto.
  - Si los datos guardados no cumplen las invariantes, la carga falla en lugar de dejar circular una receta inválida. El `CHECK` es una segunda red para lo que no pasa por el dominio.
  - Los tests de contrato garantizan que el adaptador en memoria se comporta como el real, así que los tests rápidos de los casos de uso siguen siendo fiables.
  - El reemplazo completo de ingredientes y pasos es simple y coherente con que sean *value objects*.
- **En contra:**
  - Al cargar se repiten las validaciones. Hoy el coste es despreciable.
  - Cada `save()` borra y reinserta los ingredientes y pasos, aunque no hayan cambiado.
  - No hay control de concurrencia: si dos guardados de la misma receta coinciden, gana el último. No hay bloqueo optimista porque, en un recetario personal, no es un riesgo real.
  - Los modelos de las tablas hijas no tienen clave primaria en Eloquent (`#[Table(key: false)]`), así que solo sirven para leer. Para escribir se usan consultas en bloque.
- **Revisar cuando:**
  - `Recipe::create()` haga algo que no deba repetirse al cargar, por ejemplo registrar un evento `RecipeCreated`. Entonces hará falta `Recipe::reconstitute()`.
  - Aparezca la edición concurrente. Entonces hará falta una columna `version` y bloqueo optimista.
