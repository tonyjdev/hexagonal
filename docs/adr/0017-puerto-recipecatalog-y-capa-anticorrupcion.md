# 0017. Puerto `RecipeCatalog` y capa anticorrupción hacia el recetario

**Fecha:** 2026-11-05 · **Estado:** aceptada · **Tarea:** HEXM-0025 · **Relacionado:** [ADR 0003](0003-arquitectura-hexagonal-en-src.md), [ADR 0007](0007-adaptador-http-validacion-de-forma-y-errores-de-dominio.md), [ADR 0011](0011-reglas-entre-agregados-en-el-contrato-del-repositorio.md), [ADR 0012](0012-el-reloj-como-puerto-de-aplicacion.md), [ADR 0014](0014-contrato-de-entrada-de-un-plan-con-el-formato-de-los-documentos.md), [ADR 0016](0016-referencia-entre-contextos-y-evolucion-del-documento.md)

## Contexto

Desde HEXM-0024 un plato puede apuntar a una receta con una `RecipeReference`, pero nadie comprueba que la receta exista. La regla 6 dice que **la receta debe existir**. Hacerla cumplir plantea cuatro preguntas:

1. **Cómo pregunta `MealPlanning` a `Recipes`**, si su núcleo no puede importar nada de otro contexto (ADR 0003).
2. **Dónde vive el puerto** (`Domain` o `Application`) y **qué devuelve**.
3. **Por qué capa del recetario** entra el adaptador.
4. **Cómo llega el id de la receta en el alta** (`POST /api/nutrition-plans`). En la semana 1 un plato es solo un texto (`"lunch": "Lentejas…"`), así que no tiene dónde llevarlo. Las opciones eran:
   - **(a)** claves hermanas `lunch_recipe_id` y `dinner_recipe_id` junto al texto;
   - **(b)** que el plato admita un texto o un objeto `{description, recipe_id}`;
   - **(c)** que el plato pase a ser siempre un objeto, lo que rompe el contrato.

## Decisión

1. **Un puerto propio de `MealPlanning`: `RecipeCatalog::exists(RecipeReference): bool`.**
   - Habla el idioma del contexto que pregunta (`RecipeReference`), no el del que responde (`RecipeId`, `RecipeView`).
   - Devuelve **lo mínimo**, un `bool`, porque el plan solo necesita saber si la receta existe. Devolver la receta invitaría a copiar sus datos en el plato (fuera de alcance hasta el nivel 10) y acoplaría los dos modelos. Si un día hace falta más, el puerto crecerá con un método y un DTO propios de `MealPlanning`.
2. **El puerto vive en `MealPlanning\Application`.**
   - Lo usa solo el caso de uso `RegisterNutritionPlan`. Preguntar por el estado de otro sistema es orquestación, como pedir la fecha de hoy al `Clock` (ADR 0012).
   - El dominio (`Dish`, `NutritionPlan`) sigue puro: recibe la referencia y no pregunta nada al exterior.
   - A diferencia de `NutritionPlanRepository`, que está en `Domain` porque guarda los agregados del propio contexto y su contrato incluye reglas entre planes (ADR 0011).
3. **El error es `UnknownRecipe`** (`MealPlanning\Application`, hereda de `DomainException` → HTTP 422). Por ejemplo: «El plato «Guiso de alubias y pollo» apunta a una receta que no existe en el recetario («…»)».
   - Es un dato inválido del plan que se da de alta, no un recurso que falta: por eso no es un `NotFound` (404).
   - Vive junto a su puerto: el dominio de `MealPlanning` no tiene forma de detectarlo.
4. **El caso de uso pregunta después de construir el plan y antes de guardarlo.**
   - Primero comprueba las reglas que no cuestan nada (el dominio, en memoria). Solo si el plan es válido consulta al recetario, **una vez por receta distinta**, recorriendo el agregado ya construido.
   - Si una receta no existe, no se guarda nada (todo o nada, como en el ADR 0014).
5. **La capa anticorrupción es `MealPlanning\Infrastructure\Recipes\RecipesContextRecipeCatalog`.**
   - Llama al caso de uso `Recipes\Application\GetRecipe` y traduce sus respuestas: un `RecipeView` es `true` y `RecipeNotFound` es `false`. Nada de `Recipes` sale de la clase.
   - Reutiliza `GetRecipe`, aunque cargue la receta entera para responder sí o no: el alta de un plan es rara (cada dos meses) y referencia pocas recetas. Si pesa, se añade una consulta `RecipeExists` en `Recipes\Application` y solo cambia el adaptador.
   - No captura `InvalidRecipeId`: `RecipeReference` y `RecipeId` validan igual (los dos usan `Shared\Domain\Uuid`), así que nunca llega un id mal formado.
   - `MealPlanningServiceProvider` enlaza el puerto con este adaptador. `GetRecipe` lo construye el contenedor con el *binding* de `RecipesServiceProvider`.
   - Para los tests, `InMemoryRecipeCatalog`. Los dos adaptadores pasan los mismos tests de contrato (`RecipeCatalogContractTest`).
6. **Un test de arquitectura nuevo: un contexto solo entra en otro por su capa de aplicación.**
   - La infraestructura de cada contexto no puede usar ni el `Domain` ni la `Infrastructure` de los demás.
   - El núcleo (`Domain` y `Application`) ya no podía usar ningún otro contexto.
   - Se ha comprobado que el test falla si el adaptador importa `Recipes\Domain\InvalidRecipeId`.
7. **Contrato del alta (amplía el ADR 0014): opción (a), claves hermanas.**
   - Semana 1: `{"menu": 6, "lunch": "Guiso…", "lunch_recipe_id": "0190a…", "dinner": "…", "dinner_recipe_id": null}`.
   - Semana 2: `recipe_id` dentro del objeto de cada plato, `{"name": "…", "ingredients": […], "recipe_id": "0190a…"}`.
   - Todas son opcionales: el formato anterior sigue valiendo tal cual.
   - La *Form Request* solo comprueba que sean texto (ADR 0007): ni `uuid` ni `exists:recipes,id`. Que sea un UUID lo dice el dominio (`InvalidRecipeReference`, 422) y que la receta exista, el puerto (`UnknownRecipe`, 422). Un `exists:` haría además que `MealPlanning` consultara la tabla de otro contexto, saltándose su capa de aplicación.

## Consecuencias

- **A favor:**
  - El núcleo de `MealPlanning` sigue sin conocer `Recipes`. Si el recetario cambia su caso de uso, o pasa a ser un servicio externo, solo cambia un adaptador.
  - `RegisterNutritionPlan` se prueba sin Laravel y sin el recetario: el test decide qué recetas existen.
  - La frontera entre contextos queda fijada por los tests de arquitectura en las dos direcciones: qué capa pregunta y por qué capa entra.
  - Contrato compatible: ni el *fixture* ni los clientes actuales han tenido que cambiar.
- **En contra:**
  - En la semana 1, el plato y su receta van en dos claves separadas (`lunch` y `lunch_recipe_id`). La opción (c) habría sido más uniforme con la semana 2, pero rompía el contrato.
  - Comprobar la existencia carga la receta entera (`GetRecipe`).
  - La comprobación ocurre solo **al dar de alta**: si después se borra la receta, la referencia queda colgando. Lo resuelven HEXM-0026 y HEXM-0027 con el evento `RecipeDeleted`.
  - Entre la comprobación y el guardado, otra petición podría borrar la receta. Hoy no se pueden borrar recetas; cuando se pueda, el evento de HEXM-0027 cubrirá también este caso.
- **Revisar cuando:**
  - `Recipes` deje de estar en el mismo proceso (un servicio externo): el adaptador tendrá que decidir qué hacer si el recetario no responde, porque ese fallo no es ni `true` ni `false`;
  - el plan necesite datos de la receta (nombre, ingredientes): un método nuevo del puerto con un DTO propio de `MealPlanning`, nunca el `RecipeView`.
