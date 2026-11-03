# MealPlanning (Planes y calendario)

Contexto que gestiona los **planes nutricionales**: pautas fijas (desayuno, media mañana, media tarde), intercambios, las dos semanas de menús que se alternan, sus días y platos, y el calendario ("¿qué me toca hoy?").

| Capa | Qué irá aquí (niveles 3-6 del roadmap) |
|---|---|
| `Domain/` | `NutritionPlan` (agregado), `MenuWeek`, `MenuDay`, `Dish`, `FixedMeal`, `OptionGroup`, `ExchangeTable`, `MealType`, el servicio de dominio `MealCalendar` y los puertos `NutritionPlanRepository` y `Clock`. |
| `Application/` | Casos de uso: `GetMenuForDate`, `RegisterNutritionPlan`, `ImportPlan`… |
| `Infrastructure/` | Persistencia con Eloquent, la API HTTP, el adaptador de `PlanReader` hacia el importador de FastAPI y el adaptador que consulta el recetario. |

**Relación con Recipes:** un `Dish` puede referenciar una receta **solo por su `RecipeId`**. Si este contexto necesita datos de una receta, los pide a través de un puerto propio, cuyo adaptador (en `Infrastructure/`) llama a la capa de aplicación de `Recipes`. Nunca se importan clases del dominio de `Recipes`.

**Estado (HEXM-0012):** existen los value objects y enums básicos: `NutritionPlanId`, `PlanNumber`, `MealType`, `WeekSource`, `ExchangeCategory`, `Dish` y `DishIngredient`. Los ingredientes de un plato llevan la cantidad **en texto**, no con el `Quantity` del recetario, para no cruzar la frontera entre contextos (ver el comentario de `DishIngredient`).

**Estado (HEXM-0013):** las dos semanas de menú. `Weekday` (enum ISO, 1 = lunes, con el sábado como día libre), `MenuDay` (value object: comida y cena obligatorias, nunca en sábado) y `MenuWeek` (entidad local: 6 días sin repetir, de lunes a domingo). Los formatos de los documentos (menús 1-6 y días 8-14) se traducen a días de la semana en los constructores `MenuWeek::fromNutritionistMenus()` y `MenuWeek::fromSelfDays()`.
