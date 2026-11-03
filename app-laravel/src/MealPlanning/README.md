# MealPlanning (Planes y calendario)

Contexto que gestiona los **planes nutricionales**: pautas fijas (desayuno, media mañana, media tarde), intercambios, las dos semanas de menús que se alternan, sus días y platos, y el calendario ("¿qué me toca hoy?").

| Capa | Qué irá aquí (niveles 3-6 del roadmap) |
|---|---|
| `Domain/` | `NutritionPlan` (agregado), `MenuWeek`, `MenuDay`, `Dish`, `FixedMeal`, `OptionGroup`, `ExchangeTable`, `MealType`, el servicio de dominio `MealCalendar` y el puerto `NutritionPlanRepository`. (El reloj `Clock` no está aquí: es un puerto de `Shared\Application`, porque el dominio nunca pregunta qué día es hoy; ADR 0012.) |
| `Application/` | Casos de uso: `GetMenuForDate`, `RegisterNutritionPlan`, `ImportPlan`… |
| `Infrastructure/` | Persistencia con Eloquent, la API HTTP, el adaptador de `PlanReader` hacia el importador de FastAPI y el adaptador que consulta el recetario. |

**Relación con Recipes:** un `Dish` puede referenciar una receta **solo por su `RecipeId`**. Si este contexto necesita datos de una receta, los pide a través de un puerto propio, cuyo adaptador (en `Infrastructure/`) llama a la capa de aplicación de `Recipes`. Nunca se importan clases del dominio de `Recipes`.

**Estado (HEXM-0012):** existen los value objects y enums básicos: `NutritionPlanId`, `PlanNumber`, `MealType`, `WeekSource`, `ExchangeCategory`, `Dish` y `DishIngredient`. Los ingredientes de un plato llevan la cantidad **en texto**, no con el `Quantity` del recetario, para no cruzar la frontera entre contextos (ver el comentario de `DishIngredient`).

**Estado (HEXM-0013):** las dos semanas de menú. `Weekday` (enum ISO, 1 = lunes, con el sábado como día libre), `MenuDay` (value object: comida y cena obligatorias, nunca en sábado) y `MenuWeek` (entidad local: 6 días sin repetir, de lunes a domingo). Los formatos de los documentos (menús 1-6 y días 8-14) se traducen a días de la semana en los constructores `MenuWeek::fromNutritionistMenus()` y `MenuWeek::fromSelfDays()`.

**Estado (HEXM-0014):** lo común a todas las semanas del plan. `FixedMeal` (pauta fija: desayuno, media mañana o media tarde, con parte fija en texto y grupos `OptionGroup` de `MealOption`), `Exchange` (categoría + alimento + ración en texto) y `ExchangeTable` (intercambios agrupados por categoría y reglas guardadas en texto, sin ejecutarlas hasta el nivel 8). `MealType::isFixed()` distingue las pautas fijas de las comidas del menú.

**Estado (HEXM-0019):** el primer caso de uso, `GetMenuForDate` ("¿qué me toca hoy?"). Orquesta el repositorio (`inForceOn()`), el calendario (`MealCalendar::dayOn()`) y el reloj (`Shared\Application\Clock`), y devuelve el DTO de lectura `ScheduledDayView` (con `DishView` y `DishIngredientView`). Sin plan vigente, `NoPlanInForce` (un `NotFound`).

**Estado (HEXM-0020):** los planes se guardan en PostgreSQL con `EloquentNutritionPlanRepository` (en `Infrastructure/Persistence/Eloquent`): una fila por plan en `nutrition_plans`, con el contenido del agregado en tres documentos `jsonb` que traduce `NutritionPlanMapper` (ADR 0013). Pasa los mismos tests de contrato que el adaptador en memoria. El dominio y la aplicación no han cambiado.

**Estado (HEXM-0021):** el primer comando, `RegisterNutritionPlan`, y su adaptador HTTP `POST /api/nutrition-plans` (`RegisterNutritionPlanController` y `RegisterNutritionPlanRequest`, que solo valida la forma). El JSON sigue el formato de los documentos (ADR 0014). `MealPlanningServiceProvider` enchufa el puerto de planes al adaptador de Eloquent.
