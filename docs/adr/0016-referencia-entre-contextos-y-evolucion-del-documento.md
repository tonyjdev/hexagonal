# 0016. Referencia entre contextos y evolución del documento del plan

**Fecha:** 2026-11-05 · **Estado:** aceptada · **Tarea:** HEXM-0024 · **Relacionado:** [ADR 0003](0003-arquitectura-hexagonal-en-src.md), [ADR 0005](0005-identificadores-uuid-v7-en-el-dominio.md), [ADR 0010](0010-limites-del-agregado-nutritionplan-y-vigencia.md), [ADR 0013](0013-persistencia-del-agregado-nutritionplan-como-documento.md)

## Contexto

Un plato de un plan puede corresponder a una receta del recetario (regla 6). Por ejemplo, el «guiso de alubias y pollo» del menú 6 tiene su receta. Es el primer vínculo entre los dos *bounded contexts*, `Recipes` y `MealPlanning`, y plantea dos preguntas:

1. **Cómo nombra `MealPlanning` a una receta.** Los tests de arquitectura prohíben que su núcleo use `Recipes\Domain` (ADR 0003), así que no puede usar `RecipeId`. Las opciones eran:
   - **(a)** un *value object* propio de `MealPlanning`;
   - **(b)** mover `RecipeId` al *shared kernel* (`Shared\Domain`) para que lo usen los dos.
2. **Cómo se guarda el dato nuevo** en un documento `jsonb` que ya existe (ADR 0013), con planes guardados que no lo tienen.

## Decisión

1. **Opción (a): `MealPlanning\Domain\RecipeReference`**, un *value object* que envuelve el `Uuid` del *shared kernel*, como `NutritionPlanId` y `RecipeId`.
   - El único dato que comparten los dos contextos es el **valor** del UUID. Lo que comparten de verdad es la técnica (`Uuid`), no el concepto.
   - El nombre expresa el papel: en `Recipes`, `RecipeId` es la **identidad** de su agregado; en `MealPlanning`, `RecipeReference` es una **referencia hacia fuera**, que incluso puede apuntar a algo que ya no exista (HEXM-0027).
   - Tiene su propio error, `InvalidRecipeReference`, con mensaje en español.
2. **El plato referencia la receta solo por su identidad** (ADR 0010). `Dish` gana un `?RecipeReference` opcional, válido en los dos formatos:
   - se añade con `withRecipe()`, un *wither* que devuelve un plato nuevo, porque `Dish` sigue siendo inmutable;
   - la igualdad de dos platos incluye su receta.

   El plato no guarda nada más de la receta, ni comprueba que exista: el dominio de `MealPlanning` no puede saberlo (HEXM-0025).
3. **Evolución compatible del documento.** Cada plato del documento `weeks` gana la clave `recipe_id` (un UUID o `null`).
   - El *mapper* trata **la clave ausente igual que `null`**, así que los planes guardados antes de HEXM-0024 se siguen leyendo sin migrar datos ni añadir una versión al documento.
   - Si la clave existe y no es un UUID, el documento está corrupto y la carga falla (`InvalidRecipeReference`).
4. **La lectura expone `recipe_id`** en `DishView` y en los dos *API Resources* (`GET /api/menu` y `GET /api/nutrition-plans/{id}`), con `null` si el plato no tiene receta. Es un cambio aditivo del contrato JSON.

## Consecuencias

- **A favor:**
  - Los dos contextos siguen sin dependencias entre sí, como comprueban los tests de arquitectura. `Recipes` puede cambiar su modelo sin tocar los planes.
  - PHPStan impide pasar un `RecipeId` donde se espera una `RecipeReference` y al revés: un tipo por concepto y por contexto.
  - No hizo falta migrar ningún dato: los documentos viejos y los nuevos conviven.
- **En contra:**
  - Hay dos tipos casi gemelos (`RecipeId` y `RecipeReference`) que se traducen en la frontera (HEXM-0025), entre `->value()` y `fromString()`. Es el coste, pequeño, de mantener los contextos separados.
  - Una referencia no garantiza que la receta exista: puede quedar «colgando» si se borra la receta. Lo resuelven HEXM-0025 (al dar de alta) y HEXM-0027 (al borrar).
  - Los clientes de la API que comparen el JSON de un plato exactamente ven una clave nueva.
- **Revisar cuando:**
  - un cambio en la forma del documento no se pueda leer de forma compatible, por ejemplo al renombrar o cambiar el tipo de una clave. Entonces hará falta una migración de datos o un campo de versión (ADR 0013).
