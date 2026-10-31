# Roadmap

Hoja de ruta del proyecto organizada en **niveles**. Cada nivel es pequeño, termina con tests en verde y una etiqueta git (`nivel-NN`), y añade **un concepto nuevo** cada vez.

**Estado actual:** ⬜ Nivel 1 (Recetario: dominio), siguiente. Nivel 0 terminado (`nivel-00`).

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
| ⬜ | **1. Recetario: dominio** | `Recipe`, `RecipeId`, `Ingredient`, `Quantity`, `Unit`, `Step`, `Servings` y el caso de uso `CreateRecipe` con repositorio **en memoria**. Solo tests, sin base de datos ni HTTP. | Entidad, value object, invariantes, puerto de salida, caso de uso, adaptador en memoria |
| ⬜ | **2. Recetario: adaptadores** | Repositorio con Eloquent/PostgreSQL (con su *mapper* dominio ↔ modelo), API REST (`/api/recipes`) y binding de puertos en un *service provider*. | Adaptadores *driven* y *driving*, inyección de dependencias, mapeo de persistencia |
| ⬜ | **3. Planes: dominio** | `NutritionPlan`, `MenuWeek`, `MenuDay`, `Dish`, `FixedMeal`, `OptionGroup`, `ExchangeTable`. Reglas: dos semanas, 6 días con menú, comida + cena. | Agregados y sus límites, enums, colecciones inmutables |
| ⬜ | **4. Calendario** | `MealCalendar` y el caso de uso `GetMenuForDate` ("¿qué me toca hoy?"): alternancia de semanas, sábado libre y plan vigente. Puerto `Clock`. | Servicio de dominio, tiempo testeable, casos de uso de consulta |
| ⬜ | **5. Planes: adaptadores** | Persistencia de planes, histórico de planes, API REST y alta manual de un plan. | Repositorio de agregados complejos, transacciones |
| ⬜ | **6. Relación con recetas** | Un `Dish` puede referenciar un `RecipeId`; validación entre contextos a través de un puerto propio de `MealPlanning`; evento `RecipeDeleted` y cómo reacciona `MealPlanning`. | Comunicación entre *bounded contexts*, capa anticorrupción, eventos de dominio |

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

- Frontend propio (Blade + Livewire, Inertia o una SPA).
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
