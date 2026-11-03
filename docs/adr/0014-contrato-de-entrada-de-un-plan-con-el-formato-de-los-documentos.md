# 0014. Contrato de entrada de un plan con el formato de los documentos

**Fecha:** 2026-11-04 · **Estado:** aceptada (ampliada por el [ADR 0017](0017-puerto-recipecatalog-y-capa-anticorrupcion.md): `recipe_id` opcional en cada plato) · **Tarea:** HEXM-0021 · **Relacionado:** [ADR 0007](0007-adaptador-http-validacion-de-forma-y-errores-de-dominio.md), [ADR 0013](0013-persistencia-del-agregado-nutritionplan-como-documento.md)

## Contexto

`POST /api/nutrition-plans` da de alta un plan completo. Es la primera vez que entra desde fuera un agregado tan grande: pautas fijas con grupos «elige N», tabla de intercambios con reglas y dos semanas de seis días. Las dos semanas llegan en **formatos distintos**:

- **la semana 1**, de la nutricionista, numera los menús del 1 al 6, con la comida y la cena en texto libre, y no dice a qué día corresponden;
- **la semana 2**, del usuario, numera los días del 8 al 14, cada plato con un nombre corto y sus ingredientes, y deja el 13 (el sábado) vacío.

El dominio ya sabe traducir los dos formatos: `MenuWeek::fromNutritionistMenus()` y `MenuWeek::fromSelfDays()` (HEXM-0013). Había que decidir:

1. qué forma tiene el JSON de entrada;
2. qué valida la *Form Request* y qué el dominio, con un payload tan grande;
3. qué hacer con lo que solo existe al traducir: un tipo de comida o una categoría que no existen, un menú repetido.

Opciones para el primer punto:

- **(a)** el JSON con la forma de los documentos;
- **(b)** las dos semanas normalizadas por día de la semana ISO, como se guardan en la base de datos (ADR 0013).

## Decisión

1. **Opción (a): el contrato sigue la forma de los documentos.**
   - `nutritionist_week` es una lista de `{menu, lunch, dinner}` con textos.
   - `self_week` es una lista de `{day, lunch: {name, ingredients}, dinner: …}`, y el día 13 puede ir vacío (`lunch` y `dinner` a `null`) o no aparecer.
   - Además: `number`, `starts_on` (`AAAA-MM-DD`), `fixed_meals`, `exchanges` y `exchange_rules`.
   - Las listas llevan el número dentro (`{"menu": 1, …}`) en lugar de usarlo como clave de un objeto JSON, para que el orden y los duplicados sean visibles.
   - El caso de uso usa los constructores de formato del dominio, así que la regla «el menú 6 es el domingo» sigue escrita una sola vez, en `MenuWeek`.
2. **La *Form Request* solo valida la forma** (ADR 0007): campos presentes, tipos, listas y `date_format:Y-m-d`.
   - Los textos son `nullable`: un plato vacío es una regla del dominio, no un problema de forma.
   - `meal_type` y `category` no se validan con `in:`.
   - Una petición mal formada recibe todos sus errores de forma con la ruta de cada campo (`self_week.0.lunch.ingredients.0.quantity`), sin llegar al caso de uso.
3. **Los errores de traducción son errores del dominio**, como `InvalidIngredient::unknownUnit()` en `CreateRecipe`. Los lanza el caso de uso `RegisterNutritionPlan`, con constructores nuevos en las excepciones existentes:
   - `InvalidFixedMeal::unknownMealType()`;
   - `InvalidExchangeTable::unknownCategory()`;
   - `InvalidMenuWeek::duplicatedNutritionistMenu()` y `InvalidMenuWeek::duplicatedSelfDay()`.

   Los dos últimos hacen falta porque, al indexar los menús por número, un menú repetido pisaría al anterior sin que nadie lo notara.

   Todos llegan como 422 en español, también a quien no pase por HTTP.
4. **El comando lleva primitivos más `DateTimeImmutable`** para la fecha de inicio, como `GetMenuForDate` (HEXM-0019).
5. **`MealPlanningServiceProvider`** es la raíz de composición del contexto: enlaza `NutritionPlanRepository` con `EloquentNutritionPlanRepository` y se registra en `bootstrap/providers.php`.
6. **Respuesta 201 `{"id": "…"}`, sin cabecera `Location`**: la consulta de un plan llegará en HEXM-0023.

## Consecuencias

- **A favor:**
  - Quien da de alta un plan **copia lo que ve en el papel**, sin traducir los menús a días de la semana.
  - Es el contrato natural del **importador del nivel 7**: el servicio de FastAPI podrá producir este mismo JSON a partir del Excel, y `PlanReader` solo tendrá que entregar un `RegisterNutritionPlanCommand`.
  - Las reglas del negocio están en un único sitio y protegen a todos los adaptadores de entrada.
- **En contra:**
  - El contrato de entrada y la forma guardada (ADR 0013) no coinciden: una semana entra como menús 1-6 y se guarda por días ISO. Son dos modelos de datos para propósitos distintos, y esa diferencia es intencionada.
  - Un payload grande con la validación de forma separada de la de negocio da dos tipos de 422 (`{"message", "errors"}` o solo `{"message"}`). Ya pasaba con las recetas (ADR 0007).
- **Revisar cuando:**
  - la semana 2 se elabore desde la aplicación (nivel 8): quizá convenga un contrato por días de la semana para ese canal, como **segundo adaptador** del mismo caso de uso;
  - aparezca un tercer formato de documento: entonces habrá que decidir si el comando sigue llevando los formatos de origen o una forma neutra.
