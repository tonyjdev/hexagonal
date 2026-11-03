# Roadmap

Hoja de ruta del proyecto organizada en **niveles**. Cada nivel es pequeño, termina con tests en verde y una etiqueta git (`nivel-NN`), y añade **un concepto nuevo** cada vez.

**Estado actual:** ✅ Nivel 6 (Relación con recetas) terminado (`nivel-06`): los dos contextos se comunican sin depender el uno del otro, con un puerto propio y una capa anticorrupción para preguntar, y con eventos de dominio para reaccionar. Termina el bloque A. Siguiente: nivel 7, el importador de la semana 1 con Python y FastAPI (bloque B).

Leyenda: ⬜ pendiente · 🟡 en curso · ✅ terminado

---

## Visión general

```
                 ┌──────────────── LARAVEL (núcleo hexagonal) ─────────────────┐
                 │                                                             │
 HTTP / Artisan ►│  MealPlanning              ◄── RecipeId ──    Recipes       │
                 │  NutritionPlan, MenuWeek                     Recipe         │
                 │  MenuDay, Dish, FixedMeal                    Ingredient     │
                 │  ExchangeTable, MealCalendar                                │
                 │                                                             │
                 │  Puertos de salida:                                         │
                 │   • *Repository ─────────────► Eloquent / PostgreSQL        │
                 │   • PlanReader ──────────────► HTTP ► FastAPI importador    │
                 │   • IngredientExtractor ─────► HTTP ► FastAPI + LLM         │
                 │   • RecipeFinder ────────────► HTTP ► FastAPI + pgvector    │
                 └─────────────────────────────────────────────────────────────┘
```

Para Laravel, cada servicio Python es **solo un adaptador detrás de un puerto**. El dominio nunca sabe que existe.

El roadmap tiene tres bloques:

- **Bloque A: Núcleo hexagonal en Laravel.** Aprender la arquitectura en el stack que ya se domina.
- **Bloque B: Python y FastAPI.** Servicios pequeños enchufados a puertos ya existentes (fases 1 y 2 del plan de aprendizaje).
- **Bloque C: IA aplicada.** LLM, embeddings, RAG, agentes y modelos locales (fases 3 a 7).

---

## Bloque A: Núcleo hexagonal en Laravel

| | Nivel | Qué se construye | Conceptos |
|---|---|---|---|
| ✅ | **0. Esqueleto** (HEXM-0001 a 0004) | Laravel 13 en `app-laravel/`, PostgreSQL con Docker Compose, Pest, autoload de `src/`, PHPStan y los primeros **tests de arquitectura** (el dominio no puede usar `Illuminate\*`). | Estructura hexagonal, regla de dependencias, tests de arquitectura |
| ✅ | **1. Recetario: dominio** (HEXM-0005 a 0007) | `Recipe`, `RecipeId`, `Ingredient`, `Quantity`, `Unit`, `Step`, `Servings` y el caso de uso `CreateRecipe` con repositorio **en memoria**. Solo tests, sin base de datos ni HTTP. | Entidad, value object, invariantes, puerto de salida, caso de uso, adaptador en memoria |
| ✅ | **2. Recetario: adaptadores** (HEXM-0009 a 0011) | Repositorio con Eloquent/PostgreSQL (con su *mapper* dominio ↔ modelo), API REST (`/api/recipes`) y binding de puertos en un *service provider*. | Adaptadores *driven* y *driving*, inyección de dependencias, mapeo de persistencia |
| ✅ | **3. Planes: dominio** (HEXM-0012 a 0015) | `NutritionPlan`, `MenuWeek`, `MenuDay`, `Dish`, `FixedMeal`, `OptionGroup`, `ExchangeTable`. Reglas: dos semanas, 6 días con menú, comida + cena. | Agregados y sus límites, enums, colecciones inmutables |
| ✅ | **4. Calendario** (HEXM-0017 a 0019) | `MealCalendar` y el caso de uso `GetMenuForDate` ("¿qué me toca hoy?"): alternancia de semanas, sábado libre y plan vigente. Puerto `Clock`. | Servicio de dominio, tiempo testeable, casos de uso de consulta |
| ✅ | **5. Planes: adaptadores** (HEXM-0020 a 0023) | Persistencia de planes, histórico de planes, API REST y alta manual de un plan. | Repositorio de agregados complejos, transacciones |
| ✅ | **6. Relación con recetas** (HEXM-0024 a 0027) | Un `Dish` puede referenciar un `RecipeId`; validación entre contextos a través de un puerto propio de `MealPlanning`; evento `RecipeDeleted` y cómo reacciona `MealPlanning`. | Comunicación entre *bounded contexts*, capa anticorrupción, eventos de dominio |

## Bloque B: Python y FastAPI

| | Nivel | Qué se construye | Conceptos | Plan de aprendizaje |
|---|---|---|---|---|
| ⬜ | **7. Importador de la semana 1** | Servicio `services/importer` en FastAPI: recibe el Excel de la nutricionista, lo valida y lo transforma a un JSON con un contrato definido. Laravel lo consume mediante el puerto `PlanReader`, y en los tests usa un *fake*. | Python, venv, type hints, Pydantic, FastAPI, pytest; en hexagonal, adaptador hacia un servicio externo y *contract testing* | Fases 1 y 2 |
| ⬜ | **8. La semana 2 desde la app** | Como la semana 2 la elabora el usuario, en vez de un PDF se crea directamente en la aplicación, validando las reglas de intercambios (por ejemplo, la grasa solo en una comida). Opcional: un segundo adaptador del importador para el formato propio de la semana 2. | Validación de reglas de dominio, **dos adaptadores para el mismo puerto** | – |

## Bloque C: IA aplicada

| | Nivel | Qué se construye | Conceptos | Plan de aprendizaje |
|---|---|---|---|---|
| ⬜ | **9. Ingredientes y lista de la compra** | Servicio FastAPI con LLM que convierte el texto libre de la semana 1 ("Lentejas (½ bote) con pisto…") en ingredientes estructurados. La semana 2 ya los trae. Con ambos, Laravel genera la **lista de la compra** de la semana. | Prompts, structured outputs, validación de la salida del LLM; puerto `IngredientExtractor` | Fase 3 |
| ⬜ | **10. Búsqueda semántica** | Embeddings de recetas y platos en pgvector: sugerir qué receta corresponde a un plato y buscar por significado ("algo rápido con legumbre"). | Embeddings, similitud coseno, pgvector; puerto `RecipeFinder` | Fase 4 |
| ⬜ | **11. Asistente RAG** | Preguntas sobre los planes, los intercambios y el histórico: "¿Puedo cambiar el arroz por quinoa?", "¿Qué cenaba en el plan 25?", citando la fuente. | Chunking, recuperación, contexto, citas | Fase 5 |
| ⬜ | **12. Propuesta de semana 2** | Un LLM propone una semana 2 completa; **el dominio de Laravel la valida** contra los intercambios antes de aceptarla. | Tool calling, *human-in-the-loop*, el dominio como guardián de las reglas | Fases 3 y 6 |
| ⬜ | **13. Agente** | Agente (LangGraph y/o servidor MCP) que usa la API de Laravel como herramientas: "hazme la lista de la compra de la semana que viene". | Agentes, tool calling, MCP | Fase 6 |
| ⬜ | **14. Modelos locales** | Sustituir el proveedor del LLM por Ollama (son datos de salud), **sin tocar el dominio**: solo cambia un adaptador. | Ollama, cuantización, el valor real de la hexagonal | Fase 7 |
| ⬜ | **15. Leer el PDF directamente** | Nuevo adaptador de `PlanReader` que lee el PDF de la nutricionista y evita el paso manual a Excel. | Extracción de documentos con LLM; nuevo adaptador para un puerto existente | Fases 3 y 7 |

---

## Ideas para más adelante (sin priorizar)

- Frontend propio (Blade + Livewire, Inertia o una SPA). Primer paso hecho en HEXM-0016: la página de una receta con Blade (ADR 0009).
- Escalar las raciones de una receta.
- Registro de lo que realmente se ha comido y estadísticas.
- Notificaciones ("mañana toca descongelar la merluza").
- Evaluación y observabilidad de los prompts (LLMOps, fase 10).

---

## Historial

| Fecha | Nivel | Nota |
|---|---|---|
| 2026-10-31 | – | Definición del proyecto, dominio, convenciones y roadmap. |
| 2026-10-31 | 0 | HEXM-0001: Laravel 13 + PostgreSQL 18 (pgvector) con Docker Compose. |
| 2026-10-31 | 0 | HEXM-0002: gate `composer ci:check` (Pint, Larastan `max`, Pest) y tests sobre PostgreSQL (ADR 0002). |
| 2026-10-31 | 0 | HEXM-0003: estructura hexagonal en `src/` y tests de arquitectura (ADR 0003). **Nivel 0 terminado** (`nivel-00`, diario `00-esqueleto.md`). |
| 2026-10-31 | 0 | HEXM-0004: lista blanca de dependencias en el núcleo (ADR 0004). Refuerzo posterior a `nivel-00`. |
| 2026-11-01 | 1 | HEXM-0005: value objects del recetario (`RecipeId`, `Quantity`, `Unit`, `Servings`, `Step`) y excepción de dominio base; UUID v7 generado en el dominio (ADR 0005). |
| 2026-11-01 | 1 | HEXM-0006: entidad `Recipe` (raíz de agregado) e `Ingredient`; invariantes: nombre, al menos un ingrediente, sin ingredientes repetidos, pasos opcionales. |
| 2026-11-01 | 1 | HEXM-0007: caso de uso `CreateRecipe`, puerto `RecipeRepository` y adaptador `InMemoryRecipeRepository`. **Nivel 1 terminado** (`nivel-01`, diario `01-recetario-dominio.md`). |
| 2026-11-01 | – | HEXM-0008: diagramas Archify en las tareas (transversal). `/close-task` genera diagramas respaldados por el código que acompañan a cada Explicación; diagramas del nivel 1 en `docs/diagramas/`. |
| 2026-11-01 | 2 | HEXM-0009: `EloquentRecipeRepository` (segundo adaptador del puerto), tablas del recetario, *mapper* de persistencia y tests de contrato del puerto con los dos adaptadores; reconstitución con `Recipe::create()` (ADR 0006). |
| 2026-11-01 | 2 | HEXM-0010: `POST /api/recipes`, primer adaptador *driving*: `CreateRecipeController` + `CreateRecipeRequest` (validación de forma), `RecipesServiceProvider` (*binding* del puerto a Eloquent) y traducción global de `DomainException` a HTTP 422; tests de arquitectura para HTTP y service providers (ADR 0007). |
| 2026-11-01 | 2 | HEXM-0011: `GET /api/recipes/{id}`, primer caso de uso de consulta (`GetRecipe`) con DTO de lectura (`RecipeView`), `Quantity::amountAsText()` ("1 ½"), `RecipeNotFound` → 404 e id mal formado → 422; JSON con un *API Resource* (ADR 0008). **Nivel 2 terminado** (`nivel-02`, diario `02-recetario-adaptadores.md`). |
| 2026-11-01 | 2 | HEXM-0016: primera pantalla web, `GET /recipes/{id}` con Blade: segundo adaptador de entrada sobre `GetRecipe` y `RecipeView` sin tocar el núcleo, vistas en el contexto (`recipes::`), página 404 en español y errores según el canal (ADR 0009). Refuerzo posterior a `nivel-02`. |
| 2026-11-02 | 3 | HEXM-0012: value objects y enums de `MealPlanning` (`NutritionPlanId`, `PlanNumber`, `MealType`, `WeekSource`, `ExchangeCategory`, `Dish`, `DishIngredient`); UUID v7 extraído a `Shared\Domain\Uuid` (actualización del ADR 0005); ingredientes de un plato con cantidad en texto, sin cruzar la frontera con `Recipes`. |
| 2026-11-03 | 3 | HEXM-0013: `Weekday` (enum ISO, sábado libre), `MenuDay` (value object: comida y cena obligatorias, nunca en sábado) y `MenuWeek` (entidad local: 6 días sin repetir); traducción de los formatos de origen (menús 1-6 y días 8-14) en constructores con nombre. |
| 2026-11-03 | 3 | HEXM-0014: pautas fijas (`FixedMeal` con parte fija en texto y grupos "elige N" `OptionGroup`/`MealOption`) y tabla de intercambios (`Exchange`, `ExchangeTable` agrupada por categoría con las reglas guardadas en texto, sin ejecutarlas hasta el nivel 8); `MealType::isFixed()`. |
| 2026-11-03 | 3 | HEXM-0015: agregado `NutritionPlan` (raíz): exactamente la semana 1 y la 2, inicio en lunes (`DateTimeImmutable` nativo, `Weekday::fromDate()`), como mucho una pauta fija por tipo; un único agregado con semanas y días, vigencia derivada sin fecha de fin (ADR 0010). **Nivel 3 terminado** (`nivel-03`, diario `03-planes-dominio.md`). |
| 2026-11-03 | 4 | HEXM-0017: servicio de dominio `MealCalendar` (`dayOn(plan, fecha)`) y value object `ScheduledDay`: alternancia de semanas desde el lunes de inicio, sábado libre y sin menú antes del inicio; cuenta días del calendario (ni la hora, ni la zona horaria, ni el horario de verano cambian el resultado). |
| 2026-11-03 | 4 | HEXM-0018: puerto `NutritionPlanRepository` (`save`, `find`, `inForceOn`) con `InMemoryNutritionPlanRepository`, tests de contrato y `NutritionPlanMother`; vigencia por fechas del calendario y `DuplicatedPlanStart` si otro plan empieza el mismo día: reglas entre agregados en el contrato del puerto (ADR 0011). |
| 2026-11-03 | 4 | HEXM-0019: caso de uso `GetMenuForDate` ("¿qué me toca hoy?"), primero de `MealPlanning`: orquesta `inForceOn()`, `MealCalendar` y el puerto `Shared\Application\Clock` (`today()`, interfaz propia y no PSR-20; `SystemClock` con la zona inyectada y `FrozenClock`), y devuelve el DTO `ScheduledDayView`; sin plan vigente, `NoPlanInForce` (un `NotFound`). Tests de arquitectura: el núcleo no lee el reloj del sistema (ADR 0012). **Nivel 4 terminado** (`nivel-04`, diario `04-calendario.md`). |
| 2026-11-04 | 5 | HEXM-0020: `EloquentNutritionPlanRepository`, segundo adaptador del puerto de planes: una fila por plan en `nutrition_plans` con el contenido en documentos `jsonb` (`NutritionPlanMapper`, que valida el JSON al leerlo), índice único en `starts_on` traducido a `DuplicatedPlanStart` y transacción como `SAVEPOINT` para que un duplicado no aborte la transacción de fuera; tests de contrato con los dos adaptadores, sin tocar el dominio ni la aplicación (ADR 0013). |
| 2026-11-04 | 5 | HEXM-0021: alta manual de un plan, primer comando de `MealPlanning`: `RegisterNutritionPlan` (traduce sin decidir, con los constructores de formato de `MenuWeek`) y `POST /api/nutrition-plans` con una *Form Request* que solo valida la forma; JSON con el formato de los documentos (menús 1-6 y días 8-14), contrato natural del importador del nivel 7 (ADR 0014); `MealPlanningServiceProvider` enchufa el puerto a Eloquent; errores de traducción (tipo de comida, categoría, menú o día repetidos) como errores del dominio. |
| 2026-11-04 | 5 | HEXM-0022: `GET /api/menu[?date=AAAA-MM-DD]` sobre `GetMenuForDate` sin tocar el núcleo (`GetMenuForDateRequest`, `ScheduledDayResource` con el día libre); `SharedServiceProvider` enchufa `Clock` → `SystemClock` con la zona de `config/clock.php` (Europe/Madrid, `APP_USER_TIMEZONE`), sin cambiar `app.timezone`; en los tests, «hoy» se fija sustituyendo el puerto en el contenedor (actualización del ADR 0012). |
| 2026-11-05 | 5 | HEXM-0023: histórico de planes: `ValidityPeriod` y `NutritionPlanHistory` derivan en el dominio la vigencia de cada plan del inicio del siguiente; `history()` en el puerto (agregados completos, CQS); número de plan único (`DuplicatedPlanNumber`, índice único en una migración nueva; resuelve el `domain_issue` de HEXM-0021); `ListNutritionPlans` (vigente/finalizado/próximo con el `Clock`) y `GetNutritionPlan` (`PlanNotFound`), con `GET /api/nutrition-plans[/{id}]`; `Location` en el alta (ADR 0015). **Nivel 5 terminado** (`nivel-05`, diario `05-planes-adaptadores.md`). |
| 2026-11-05 | 6 | HEXM-0024: un `Dish` puede apuntar a una receta del recetario con `RecipeReference`, value object propio de `MealPlanning` sobre el `Uuid` del shared kernel (no el `RecipeId` de `Recipes`: los contextos solo comparten el valor); `withRecipe()` inmutable; `recipe_id` en el documento `weeks` con lectura compatible de los planes antiguos, sin migrar datos; expuesto en la API (ADR 0016). |
| 2026-11-05 | 6 | HEXM-0025: la receta de un plato tiene que existir (regla 6): puerto `RecipeCatalog::exists()` en `MealPlanning\Application` (como el `Clock`), `UnknownRecipe` (422) y capa anticorrupción `RecipesContextRecipeCatalog` sobre `Recipes\Application\GetRecipe`, con `InMemoryRecipeCatalog` y tests de contrato de los dos; test de arquitectura: un contexto solo entra en otro por su capa de aplicación; el alta acepta `lunch_recipe_id`/`dinner_recipe_id` (semana 1) y `recipe_id` (semana 2), compatible con el contrato anterior (ADR 0017).
| 2026-11-05 | 6 | HEXM-0026: borrar una receta y anunciarlo: `DeleteRecipe` y `DELETE /api/recipes/{id}` (204/404/422), `RecipeRepository::delete()` idempotente (en PostgreSQL, en cascada); primer evento de dominio, `RecipeDeleted`, que registra el propio agregado (`Recipe::delete()`, *trait* `RecordsEvents`) con datos primitivos y el día del `Clock`; puerto `Shared\Application\EventBus` con `LaravelEventBus` (*dispatcher* de Laravel, publica después del *commit* con `afterCommit()`) e `InMemoryEventBus` (ADR 0018).
| 2026-11-05 | 6 | HEXM-0027: `MealPlanning` reacciona al borrado de una receta: el oyente `RecipeDeletedListener` (adaptador de entrada) traduce `RecipeDeleted` al caso de uso `UnlinkDeletedRecipe`, que busca los planes con `usingRecipe()` (SQL/JSON sobre `jsonb`) y guarda la versión nueva de cada uno (`NutritionPlan::withoutRecipe()`, mismo id): regla 24, los platos se desvinculan. Los eventos son contrato público de un contexto (`publicEvents()` en el test de arquitectura); si la reacción falla, el borrado se mantiene y el error se registra (ADR 0019). **Nivel 6 terminado** (`nivel-06`, diario `06-relacion-con-recetas.md`). |
