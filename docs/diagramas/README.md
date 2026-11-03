# Diagramas

Cada tarea `HEXM` cerrada deja, **siempre que tenga algo que dibujar**, uno o varios diagramas que acompañan a su [Explicación](../explicaciones/README.md): el flujo de datos, la lógica de negocio, la arquitectura o la estructura de lo construido. Se generan con [Archify](https://github.com/tt-a1i/archify) (skill instalada en `~/.agents/skills/archify`) como HTML interactivos: zoom, tema claro/oscuro, animación del recorrido y exportación a SVG, PNG o WebM.

## Cómo verlos

En git solo se versiona la **fuente** de cada diagrama (`<slug>.<tipo>.json`, unos pocos KB). El HTML ocupa unos 850 KB porque lleva el visor dentro, así que se genera en local:

```bash
cd app-laravel && composer docs:diagrams     # o, desde la raíz: scripts/diagramas.sh [HEXM-NNNN]
```

Después se abre `docs/diagramas/HEXM-NNNN/<slug>.html` en el navegador. Requiere Node y la skill de Archify; con `ARCHIFY_CLI=/ruta/archify.mjs` se usa otra instalación.

## Convenciones

- **Ubicación:** `docs/diagramas/HEXM-NNNN/<slug>.<tipo>.json`, con `meta.output` apuntando a `docs/diagramas/HEXM-NNNN/<slug>.html`. El tipo en el nombre del fichero permite al script saber cómo renderizarlo.
- **Idioma:** `meta.locale: "es"`. Títulos, etiquetas y tarjetas en español; los nombres de código (`Recipe`, `save()`) tal cual.
- **Respaldados por el código:** cada elemento principal cita sus ficheros y líneas (`sources`) en un commit fijo (`meta.repository.revision`, con la URL HTTPS de GitHub). Archify comprueba que esas líneas existen en ese commit, y el visor enlaza cada elemento a su código en GitHub.
- **Calidad:** se generan con `archify finalize … --repo-root . --quality showcase`, que valida el esquema, renderiza, comprueba el artefacto y hace una prueba real en navegador.
- **Leyenda con el vocabulario del proyecto:** los tipos de nodo de Archify (`backend`, `security`, `database`…) se reetiquetan en `meta.legend.entries` (por ejemplo, `security` → "Dominio: reglas de negocio", `messagebus` → "Puerto (interfaz)").

## Qué tipo de diagrama usar

| Para explicar… | Tipo | Ejemplo |
|---|---|---|
| Capas, puertos, adaptadores y el sentido de las dependencias | `architecture` | [Hexágono del recetario](HEXM-0007/hexagono-recetario.architecture.json) |
| El recorrido de una petición o de un caso de uso entre objetos | `sequence` | [CreateRecipe paso a paso](HEXM-0007/create-recipe-paso-a-paso.sequence.json) |
| Una regla de negocio o una validación con sus ramas de error | `workflow` | [De texto a cantidad exacta](HEXM-0005/de-texto-a-cantidad-exacta.workflow.json) |
| Entidades, value objects y sus relaciones (agregados) | `class` | [Agregado Recipe](HEXM-0006/agregado-recipe.class.json) |
| Estados y transiciones (un plan vigente, una importación…) | `lifecycle` | — |
| Tablas, claves y relaciones de la base de datos (nivel 2 en adelante) | `erd` | [Tablas del recetario](HEXM-0009/tablas-del-recetario.erd.json) |
| Datos que fluyen entre servicios o capas y cambian de forma (Laravel ↔ FastAPI, embeddings…) | `dataflow` | [Del papel a la base de datos](HEXM-0021/del-papel-a-la-base-de-datos.dataflow.json) |
| Qué pasa en cada fecha, sobre un eje de tiempo proporcional (calendario, historial) | `timeline` | [Alternancia de semanas](HEXM-0017/alternancia-de-semanas.timeline.json) |
| Una estructura de contención (qué está dentro de qué): la forma de un documento, un árbol de módulos | `tree` | [Un plan, una fila](HEXM-0020/un-plan-una-fila.tree.json) |

Si una tarea no tiene nada que dibujar (por ejemplo, un cambio de configuración), su Explicación lo dice en la sección "Diagramas".

## Índice

### Nivel 1: Recetario (dominio)

| Tarea | Diagrama | Qué muestra |
|---|---|---|
| HEXM-0005 | [De texto a cantidad exacta](HEXM-0005/de-texto-a-cantidad-exacta.workflow.json) (`workflow`) | Cómo "1 ½" se convierte en la fracción exacta 3/2 y cuándo salta cada error de `InvalidQuantity`. |
| HEXM-0006 | [Agregado Recipe](HEXM-0006/agregado-recipe.class.json) (`class`) | La entidad raíz, sus value objects, la composición, las invariantes y la jerarquía de excepciones. |
| HEXM-0007 | [CreateRecipe paso a paso](HEXM-0007/create-recipe-paso-a-paso.sequence.json) (`sequence`) | El recorrido de los datos de un alta, tanto válida como inválida. |
| HEXM-0007 | [Hexágono del recetario](HEXM-0007/hexagono-recetario.architecture.json) (`architecture`) | Las capas, el puerto, el adaptador y la inversión de dependencias al cerrar el nivel 1. |

### Nivel 2: Recetario (adaptadores)

| Tarea | Diagrama | Qué muestra |
|---|---|---|
| HEXM-0009 | [Tablas del recetario](HEXM-0009/tablas-del-recetario.erd.json) (`erd`) | El agregado `Recipe` repartido en tres tablas: el uuid de la raíz, la clave compuesta `(recipe_id, position)` de los value objects y la cantidad exacta en tres columnas. |
| HEXM-0009 | [Guardar y cargar una receta](HEXM-0009/guardar-y-cargar-una-receta.sequence.json) (`sequence`) | `save()` dentro de una transacción (con el ROLLBACK si falla a mitad) y `find()` reconstruyendo la receta con `Recipe::create()`. |
| HEXM-0010 | [Alta de una receta por HTTP](HEXM-0010/alta-de-receta-por-http.sequence.json) (`sequence`) | `POST /api/recipes` de punta a punta en sus tres caminos: 201 con la receta guardada, 422 de validación sin llegar al controlador y 422 del dominio traducido por el manejador global. |
| HEXM-0010 | [Forma frente a reglas](HEXM-0010/forma-frente-a-reglas.workflow.json) (`workflow`) | Quién rechaza cada petición: la frontera HTTP comprueba campos y tipos; el núcleo, las reglas de negocio. |
| HEXM-0011 | [Consultar una receta](HEXM-0011/consultar-una-receta.sequence.json) (`sequence`) | `GET /api/recipes/{id}` en sus tres resultados: 200 con el DTO de lectura (`RecipeView`) formateado por `RecipeResource`, 404 (`RecipeNotFound`) y 422 (id mal formado). |
| HEXM-0011 | [Hexágono del recetario al cerrar el nivel 2](HEXM-0011/hexagono-recetario-nivel-2.architecture.json) (`architecture`) | Los dos adaptadores HTTP, el comando y la consulta, el puerto con sus dos implementaciones (Eloquent y memoria) y el *provider* que elige Eloquent. |
| HEXM-0016 | [Dos canales, un caso de uso](HEXM-0016/dos-canales-un-caso-de-uso.architecture.json) (`architecture`) | La API y la web entran por controladores distintos, comparten `GetRecipe` y `RecipeView` sin cambios, y se separan en la presentación (`RecipeResource` o la vista Blade). |
| HEXM-0016 | [Errores según el canal](HEXM-0016/errores-segun-el-canal.workflow.json) (`workflow`) | Qué ve el cliente al pedir una receta en cada canal: página 404 en español en la web (también para `abc`, por `whereUuid`) y 404/422 en JSON en la API. |

### Nivel 3: Planes (dominio)

| Tarea | Diagrama | Qué muestra |
|---|---|---|
| HEXM-0012 | [Piezas del plan](HEXM-0012/piezas-del-plan.class.json) (`class`) | Los value objects y enums con que arranca `MealPlanning`: `Dish` con sus dos formatos y sus `DishIngredient`, `NutritionPlanId` sobre `Uuid`, `PlanNumber` y las excepciones de dominio. |
| HEXM-0012 | [Shared kernel entre dos contextos](HEXM-0012/shared-kernel-entre-contextos.architecture.json) (`architecture`) | Qué comparten `Recipes` y `MealPlanning` (`Uuid` y `DomainException`, en `Shared\Domain`) y qué no (`Quantity`), sin ninguna flecha entre los dos contextos. |
| HEXM-0013 | [Semana de menú](HEXM-0013/semana-de-menu.class.json) (`class`) | `MenuWeek` (entidad local) compuesta por 6 `MenuDay` (value objects) con comida y cena, la clave `Weekday`, el origen `WeekSource` y las excepciones `InvalidMenuWeek` e `InvalidMenuDay`. |
| HEXM-0013 | [De los documentos a la semana](HEXM-0013/de-los-documentos-a-la-semana.workflow.json) (`workflow`) | Cómo los menús 1-6 y los días 8-14 se traducen a días de la semana y pasan por las mismas reglas (sábado, comida y cena, semana 1 o 2, repetidos, 6 días) hasta un `MenuWeek`. |
| HEXM-0014 | [Pautas fijas e intercambios](HEXM-0014/pautas-fijas-e-intercambios.class.json) (`class`) | `FixedMeal` compuesta por grupos `OptionGroup` de `MealOption`, ligada a `MealType::isFixed()`, y `ExchangeTable` agrupando `Exchange` por `ExchangeCategory`, con la excepción que lanza cada pieza. |
| HEXM-0014 | [¿Es válida esta pauta fija?](HEXM-0014/es-valida-esta-pauta-fija.workflow.json) (`workflow`) | Las reglas de un grupo "elige N" (rango de N, opciones repetidas) y de la pauta (comida fija, nunca vacía) hasta un `FixedMeal`, con cada error. |
| HEXM-0015 | [Agregado NutritionPlan](HEXM-0015/agregado-nutritionplan.class.json) (`class`) | La raíz `NutritionPlan` y lo que contiene su frontera de consistencia (2 `MenuWeek` con 6 `MenuDay`, 0..3 `FixedMeal`, una `ExchangeTable`), los value objects que usa (`NutritionPlanId`, `PlanNumber`, `Weekday::fromDate()`) y lo que queda fuera (recetas, otros planes). |
| HEXM-0015 | [¿Es válido este plan?](HEXM-0015/es-valido-este-plan.workflow.json) (`workflow`) | Las invariantes de `NutritionPlan::create()` en su orden real (lunes, pautas sin repetir, semana repetida, semana que falta) con el error en español de cada una. |

### Nivel 4: Calendario

| Tarea | Diagrama | Qué muestra |
|---|---|---|
| HEXM-0017 | [¿Qué toca el día X?](HEXM-0017/que-toca-el-dia-x.workflow.json) (`workflow`) | `MealCalendar::dayOn()` paso a paso: días del calendario en UTC, fecha anterior al inicio (error), semana que toca por paridad y sábado libre o día con menú (`ScheduledDay`). |
| HEXM-0017 | [Alternancia de semanas](HEXM-0017/alternancia-de-semanas.timeline.json) (`timeline`) | Las fechas de los tests del plan 27 sobre el tiempo: semanas 1 y 2 alternándose, sábados libres, la fecha anterior al inicio y el cambio de hora de marzo de 2027. La leyenda genérica de Archify se explica en la tarjeta "Cómo leerlo" (el tipo `timeline` no admite `meta.legend`). |
| HEXM-0018 | [Repositorio de planes](HEXM-0018/repositorio-de-planes.architecture.json) (`architecture`) | El puerto `NutritionPlanRepository` en el dominio con su contrato (vigencia y planes que empiezan el mismo día), el adaptador en memoria que lo implementa y lanza `DuplicatedPlanStart`, y el de Eloquent que llegará en el nivel 5 (ADR 0011). |
| HEXM-0018 | [Vigencia de los planes 26 y 27](HEXM-0018/vigencia-de-los-planes.timeline.json) (`timeline`) | Qué plan devuelve `inForceOn()` en cada fecha de ejemplo (ninguno, el 26, el 27; del último minuto del 1 de noviembre al primero del 2) y el plan 28 rechazado por empezar el mismo día que el 27. |
| HEXM-0019 | [¿Qué me toca hoy?](HEXM-0019/que-me-toca-hoy.sequence.json) (`sequence`) | `GetMenuForDate` en sus tres caminos: sin fecha pide `today()` al `Clock` (miércoles 11/11 → semana 2), con fecha explícita el reloj ni se consulta (sábado 14/11 → día libre) y sin plan vigente lanza `NoPlanInForce` (un `NotFound`). |
| HEXM-0019 | [El reloj como puerto](HEXM-0019/el-reloj-como-puerto.architecture.json) (`architecture`) | Dónde vive "hoy": el puerto `Clock` en `Shared\Application` (el dominio no lo ve), sus adaptadores `FrozenClock` y `SystemClock` en `Shared\Infrastructure` y el test de arquitectura que impide leer el reloj del sistema en el núcleo (ADR 0012). |

### Nivel 5: Planes (adaptadores)

| Tarea | Diagrama | Qué muestra |
|---|---|---|
| HEXM-0020 | [Un plan, una fila](HEXM-0020/un-plan-una-fila.tree.json) (`tree`) | Cómo queda guardado un plan en `nutrition_plans`: las tres columnas que consulta el repositorio (`starts_on` con índice único) y la estructura de los documentos `jsonb` `fixed_meals`, `exchange_table` y `weeks`, hasta los ingredientes de un plato (ADR 0013). |
| HEXM-0020 | [Guardar un plan en un día ocupado](HEXM-0020/guardar-en-un-dia-ocupado.sequence.json) (`sequence`) | `save()` de un plan ya guardado movido al inicio de otro: `SAVEPOINT`, violación del índice único, `ROLLBACK TO SAVEPOINT`, consulta del plan que ocupa el día y `DuplicatedPlanStart`. |
| HEXM-0021 | [Del papel a la base de datos](HEXM-0021/del-papel-a-la-base-de-datos.dataflow.json) (`dataflow`) | Las tres formas del mismo plan al cruzar las capas: menús 1-6 y días 8-14 en el JSON, días de la semana en el dominio (`MenuWeek`) y documentos `jsonb` en `nutrition_plans`; y dónde se rechaza cada error (422 de forma o del dominio). |
| HEXM-0021 | [Hexágono de MealPlanning](HEXM-0021/hexagono-mealplanning.architecture.json) (`architecture`) | El primer recorrido completo del contexto: controlador y *Form Request*, caso de uso, agregado y puerto en el dominio, Eloquent y el `MealPlanningServiceProvider` que los enchufa. |
| HEXM-0022 | [¿Qué me toca hoy? por HTTP](HEXM-0022/menu-de-hoy-por-http.sequence.json) (`sequence`) | `GET /api/menu` en sus tres caminos: hoy según el reloj real (`SystemClock` en Europe/Madrid, martes de la semana 1), un día concreto sin consultar el reloj (sábado libre) y el 404 sin plan vigente. |
| HEXM-0022 | [Tres raíces de composición](HEXM-0022/raices-de-composicion.architecture.json) (`architecture`) | Quién enchufa cada puerto: `SharedServiceProvider` (el reloj, con la zona de `config/clock.php`), `MealPlanningServiceProvider` y `RecipesServiceProvider`, y cómo los tests sustituyen el reloj en el contenedor. |
| HEXM-0023 | [¿Vigente, finalizado o próximo?](HEXM-0023/vigente-finalizado-o-proximo.workflow.json) (`workflow`) | Cómo sale cada plan del histórico: el dominio ordena los planes y deriva el periodo de cada uno del inicio del siguiente, y la consulta lo compara con hoy (`Clock`) para decir si está vigente, finalizado o es próximo. |
| HEXM-0023 | [Las piezas del histórico](HEXM-0023/piezas-del-historico.class.json) (`class`) | `NutritionPlanHistory` y `ValidityPeriod` en el dominio, el puerto con `history()` y `DuplicatedPlanNumber`, y las consultas `ListNutritionPlans` y `GetNutritionPlan` con sus vistas, `PlanStatus` y `PlanNotFound`. |

### Nivel 6: Relación con recetas

| Tarea | Diagrama | Qué muestra |
|---|---|---|
| HEXM-0024 | [Un UUID, dos tipos](HEXM-0024/un-uuid-dos-tipos.class.json) (`class`) | `RecipeId` (identidad en `Recipes`) y `RecipeReference` (referencia en `MealPlanning`) envuelven el mismo `Uuid` del shared kernel sin depender uno del otro; el `Dish` que apunta a 0..1 receta y el error `InvalidRecipeReference`. |
| HEXM-0024 | [Leer el recipe_id de un plato](HEXM-0024/leer-el-recipe-id.workflow.json) (`workflow`) | La lectura compatible del documento `weeks`: clave ausente o `null` (planes anteriores) → plato sin receta; UUID → plato con receta; otro valor → datos corruptos, no se carga (ADR 0016). |
| HEXM-0025 | [Capa anticorrupción](HEXM-0025/capa-anticorrupcion.architecture.json) (`architecture`) | Cómo pregunta `MealPlanning` al recetario: el caso de uso usa su puerto propio `RecipeCatalog` (en `Application`), la capa anticorrupción `RecipesContextRecipeCatalog` lo traduce a `GetRecipe`, y el test de arquitectura prohíbe entrar en `Recipes\Domain` (ADR 0017). |
| HEXM-0025 | [Alta de un plan con recetas](HEXM-0025/alta-con-receta.sequence.json) (`sequence`) | `POST /api/nutrition-plans` en sus tres caminos: receta existente (201), receta inexistente (`RecipeNotFound` → `false` → `UnknownRecipe`, 422 y nada guardado) e id mal formado (422 sin preguntar al recetario). |

### Transversal

| Tarea | Diagrama | Qué muestra |
|---|---|---|
| HEXM-0008 | [Cierre de una tarea con diagramas](HEXM-0008/cierre-de-tarea-con-diagramas.workflow.json) (`workflow`) | El flujo de `/close-task` con los dos commits, el bucle de validación y reparación de Archify y la regeneración local de los HTML. |
