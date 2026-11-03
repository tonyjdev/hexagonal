# Dominio: lenguaje ubicuo y modelo

Este documento describe **el problema de negocio** con independencia de la tecnología. Es la referencia para nombrar cosas en el código.

> **Lenguaje ubicuo** (DDD): usar siempre las mismas palabras para lo mismo, en las conversaciones, en la documentación y en el código. Como el código va en inglés, este glosario fija la traducción oficial de cada término.

---

## 1. El problema

Cada ~2 meses la nutricionista entrega un **plan nutricional** numerado (por ejemplo, el *plan 27*). El plan se sigue con **seguimiento**: cuando llega uno nuevo, el anterior deja de estar vigente, pero conviene conservarlo como histórico.

Un plan se compone de:

1. **Pautas diarias fijas**: desayuno, media mañana y media tarde. Son iguales todos los días y en todas las semanas. Algunas tienen **opciones a elegir** ("elegir 2 de las siguientes").
2. **Intercambios**: equivalencias por categoría (hidratos, proteínas, vegetales, grasas) que permiten sustituir un alimento por otro. Llevan reglas, por ejemplo: *"la legumbre cuenta como ½ intercambio de proteína"* o *"la grasa solo en la comida o en la cena, no en ambas"*. Pueden cambiar de un plan a otro.
3. **Dos semanas de comidas y cenas** que se alternan:
   - **Semana 1**: la elabora la nutricionista. Menús numerados del 1 al 6, cada uno con comida y cena en texto libre.
   - **Semana 2**: la elabora el propio usuario, con otro formato: días 8 a 14, cada comida y cena con un nombre corto ("Pasta con salmón y pesto") y una lista de ingredientes con cantidades.
4. **Recetas** que a veces vienen incluidas (por ejemplo, *guiso de alubias y pollo*), o que están implícitas en los platos (el *pisto* de la semana 1 detalla ingredientes y raciones).

### Calendario

- Cada semana del plan empieza en **lunes**.
- El **sábado es día libre**: dieta menos estricta y momento para aprovechar los sobrantes de la semana.
- Por tanto, cada semana tiene **6 días con menú**: lunes a viernes y domingo.
  - Semana 1: menú 1 → lunes, menú 2 → martes … menú 5 → viernes, *(sábado libre)*, menú 6 → domingo.
  - Semana 2: día 8 → lunes … día 12 → viernes, día 13 → sábado (libre), día 14 → domingo.
- Las semanas se **alternan**: 1, 2, 1, 2… desde la fecha de inicio del plan.
- Pregunta clave que debe responder la aplicación: **"¿Qué me toca comer hoy (o el día X)?"**

> Confirmado: **en ambas semanas** hay 6 días con menú y el sábado es libre. La diferencia es solo de formato: la semana 1 numera los menús del 1 al 6 sin decir a qué día corresponden (se asignan en orden saltando el sábado), mientras que la semana 2 los organiza por días y deja el sábado explícitamente vacío. El calendario es una regla del dominio, no algo que dependa del formato del documento.

---

## 2. Contextos delimitados (*bounded contexts*)

| Contexto | Namespace | Responsabilidad |
|---|---|---|
| **Recetario** | `Recipes` | Gestionar recetas: ingredientes, cantidades, pasos, raciones. No sabe nada de planes ni de calendarios. |
| **Planes y calendario** | `MealPlanning` | Planes nutricionales, pautas fijas, intercambios, semanas, días, platos y calendario. Referencia recetas **solo por su identificador** (`RecipeId`). |

Más adelante pueden aparecer otros (por ejemplo, `Shopping` para la lista de la compra) si la complejidad lo justifica.

---

## 3. Glosario

### Recetario (`Recipes`)

| Español | Código | Tipo DDD | Notas |
|---|---|---|---|
| Receta | `Recipe` | Entidad / raíz de agregado | Nombre, raciones, ingredientes y pasos ordenados. Se compara por su `RecipeId`; cambia con métodos de negocio (`rename()`), no con setters. |
| Identificador de receta | `RecipeId` | Value object | UUID v7 generado por el dominio ([ADR 0005](adr/0005-identificadores-uuid-v7-en-el-dominio.md)), sobre el `Uuid` del *shared kernel*. Lo único que conoce `MealPlanning` de una receta. |
| Ingrediente | `Ingredient` | Value object | Nombre + cantidad opcional: `Ingredient::of()` con cantidad, `Ingredient::toTaste()` "al gusto". El nombre se compara sin distinguir mayúsculas. |
| Cantidad | `Quantity` | Value object | Número positivo + unidad. El número es una fracción exacta (numerador/denominador): ½, ⅓, ¾, 1 ½, 1,5… Se lee con `fromString()` y se escribe como en los planes con `amountAsText()` (3/2 → "1 ½"). |
| Unidad | `Unit` | Enum | gramo `Gram`, mililitro `Millilitre`, unidad `Piece`, cucharada sopera `Tablespoon`, cucharadita `Teaspoon`, vaso `Glass`, loncha `Slice`, filete `Fillet`, lata `Can`, bote `Jar`. |
| Paso de preparación | `Step` | Value object | Texto no vacío. El orden lo da su posición en la receta. |
| Raciones | `Servings` | Value object | Al menos 1. |
| Receta eliminada | `RecipeDeleted` | Evento de dominio | |
| Repositorio de recetas | `RecipeRepository` | Puerto (de salida) | Guardar (`save`) y recuperar (`find`) recetas completas. Adaptadores: `InMemoryRecipeRepository` (tests) y `EloquentRecipeRepository` (PostgreSQL, [ADR 0006](adr/0006-persistencia-del-agregado-recipe.md)); los dos cumplen los mismos tests de contrato. |
| Crear receta | `CreateRecipe` | Caso de uso | Recibe un `CreateRecipeCommand` con datos primitivos, crea la receta y la guarda. Devuelve su `RecipeId`. Por HTTP: `POST /api/recipes` (adaptador `CreateRecipeController`, [ADR 0007](adr/0007-adaptador-http-validacion-de-forma-y-errores-de-dominio.md)). |
| Consultar receta | `GetRecipe` | Caso de uso (consulta) | Recibe el id y devuelve un `RecipeView`; no cambia nada (CQS). Por HTTP: `GET /api/recipes/{id}` ([ADR 0008](adr/0008-consultas-dto-de-lectura-y-no-encontrado.md)); en la web, `GET /recipes/{id}` ([ADR 0009](adr/0009-adaptador-web-vistas-en-el-contexto-y-errores-por-canal.md)). |
| Vista de receta | `RecipeView`, `IngredientView` | DTO de lectura | La receta lista para mostrar, con primitivos: cantidad legible ("1 ½"), código y nombre de la unidad, y si es al gusto. La entidad `Recipe` nunca sale de la aplicación. |
| Receta no encontrada | `RecipeNotFound` | Excepción (`Recipes\Application`) | No existe ninguna receta con ese id. Hereda de `NotFound`, no de `DomainException`. |
| No encontrado | `NotFound` | Excepción (`Shared\Application`) | Base de "lo que buscas no existe", para todos los contextos. En la API llega como HTTP 404 `{"message": "…"}`. |
| Al gusto / una pizca | — | Concepto | Cantidad sin número: no es una `Quantity`, sino la ausencia de cantidad del ingrediente. |
| Error de dominio | `DomainException` | Excepción (`Shared\Domain`) | Base de los errores de negocio, con mensaje en español para el usuario: `InvalidQuantity`, `InvalidServings`, `InvalidStep`, `InvalidRecipeId`, `InvalidIngredient`, `InvalidRecipe` y, en `MealPlanning`, `InvalidNutritionPlanId`, `InvalidPlanNumber`, `InvalidDish`, `InvalidMenuDay`, `InvalidMenuWeek`, `InvalidFixedMeal`, `InvalidOptionGroup`, `InvalidExchangeTable`. En la API llegan como HTTP 422 `{"message": "…"}`, traducidos en un único manejador ([ADR 0007](adr/0007-adaptador-http-validacion-de-forma-y-errores-de-dominio.md)). |

### Planes y calendario (`MealPlanning`)

| Español | Código | Tipo DDD | Notas |
|---|---|---|---|
| Plan nutricional | `NutritionPlan` | Entidad / raíz de agregado | Número (27), fecha de inicio, vigencia. |
| Identificador de plan | `NutritionPlanId` | Value object | UUID v7 generado por el dominio, sobre el `Uuid` del *shared kernel* (como `RecipeId`). |
| Número de plan | `PlanNumber` | Value object | Entero positivo que asigna la nutricionista ("plan 27"). Es un dato del negocio, no la identidad del plan. |
| Tipo de comida | `MealType` | Enum | `Breakfast` (desayuno), `MidMorning` (media mañana), `Lunch` (comida), `AfternoonSnack` (media tarde), `Dinner` (cena), en el orden del día. Etiqueta en español con `label()`; `isFixed()` dice si es una pauta fija (desayuno, media mañana, media tarde). |
| Pauta fija | `FixedMeal` | Value object | Desayuno, media mañana o media tarde (nunca comida ni cena): iguales todos los días. Una parte fija opcional en texto libre (lo que se toma siempre) más sus grupos de opciones; nunca vacía (HEXM-0014). |
| Grupo de opciones | `OptionGroup` | Value object | "Elegir N de las siguientes": `OptionGroup::choose(N, opciones)`, con N ≥ 1, N ≤ número de opciones y sin opciones repetidas. |
| Opción | `MealOption` | Value object | Una opción de un grupo, en texto libre ("Yogur natural desnatado"). Nunca vacía; se compara sin distinguir mayúsculas ni espacios. |
| Intercambio | `Exchange` | Value object | Una equivalencia: categoría + alimento + ración ("Arroz", "60 g en crudo"). La ración es texto y obligatoria. Un alimento puede estar en dos categorías (la legumbre). |
| Ración (de un intercambio) | `serving` | Atributo de `Exchange` | La cantidad que equivale a un intercambio, tal como la escribe la nutricionista. |
| Categoría de intercambio | `ExchangeCategory` | Enum | `Carbohydrates` (hidratos), `Protein` (proteínas), `Vegetables` (vegetales), `Fat` (grasas). |
| Tabla de intercambios | `ExchangeTable` | Value object | Intercambios agrupados por categoría (`exchangesIn()`), en el orden del documento, y las reglas del plan en texto y en orden (`rules()`). Las reglas se guardan; hacerlas cumplir contra un menú es del nivel 8 (HEXM-0014). |
| Regla de intercambio | — | Concepto (texto en `ExchangeTable`) | "La legumbre cuenta como ½ intercambio de proteína", "la grasa solo en la comida o en la cena". Hoy es texto; puede pasar a código en el nivel 8. |
| Semana de menú | `MenuWeek` | Entidad (local) | Semana 1 o 2 de un plan: su número es su identidad dentro del plan. Exactamente 6 días con menú, sin repetir ninguno, ordenados de lunes a domingo. Se construye desde los dos formatos de origen: `fromNutritionistMenus()` (menús 1-6, el 6 cae en domingo) y `fromSelfDays()` (días 8-14, el 13 vacío); o con `create()` a partir de sus días (HEXM-0013). |
| Origen de la semana | `WeekSource` | Enum | `Nutritionist` (semana 1, "nutricionista"), `Self` (semana 2, "elaboración propia"). |
| Día de menú | `MenuDay` | Value object | Día de la semana + comida + cena, ambas obligatorias. Nunca en sábado. Es value object y no entidad: su día de la semana es una clave dentro de su semana, no una identidad con ciclo de vida; cambiar un plato es sustituir el día entero (HEXM-0013). |
| Día de la semana | `Weekday` | Enum | `Monday` (1) … `Sunday` (7), con la numeración ISO de PHP (`$date->format('N')`). Etiqueta en español con `label()`; `isFreeDay()` dice si es el día libre. |
| Plato | `Dish` | Value object | Dos formatos: semana 1 `Dish::described()` (texto libre) y semana 2 `Dish::named()` (nombre corto + ingredientes opcionales). Nunca vacío. Opcionalmente un `RecipeId` (nivel 6). |
| Ingrediente de un plato | `DishIngredient` | Value object | Nombre + cantidad opcional **en texto** ("80 g", "½ bote"). No usa el `Quantity` del recetario (frontera entre contextos); si la lista de la compra necesita sumar cantidades, se decidirá en el nivel 9 (HEXM-0012). |
| Día libre | `FreeDay` | Concepto | El sábado: `Weekday::Saturday->isFreeDay()`. No puede tener menú. |
| Calendario de comidas | `MealCalendar` | Servicio de dominio | Calcula qué semana y qué día de menú corresponde a una fecha. |
| Reloj | `Clock` | Puerto | Abstracción de "la fecha de hoy", para poder testear. |
| Importación de plan | `PlanImport` | Caso de uso | Leer un plan desde un fichero externo. |
| Lector de planes | `PlanReader` | Puerto | Implementado por adaptadores (Excel vía FastAPI, PDF…). |

### Futuros (se definirán al llegar al nivel correspondiente)

| Español | Código | Nivel aproximado |
|---|---|---|
| Lista de la compra | `ShoppingList` | Bloque C |
| Extractor de ingredientes | `IngredientExtractor` | Bloque C |
| Buscador de recetas | `RecipeFinder` | Bloque C |

---

## 4. Reglas de negocio identificadas

1. Un plan tiene exactamente **dos semanas** que se alternan, empezando por la semana 1.
2. Cada semana tiene **6 días con menú** (lunes–viernes y domingo), sin repetir ninguno; el sábado es libre y **no puede tener menú**. La numeración de los documentos (menús 1-6 en la semana 1, días 8-14 en la semana 2) es solo formato: el menú 6 es el domingo y el día 13 es el sábado vacío.
3. Cada día con menú tiene **una comida y una cena**, las dos obligatorias.
4. Las pautas fijas y los intercambios son **comunes a todas las semanas** del plan, pero **pueden cambiar entre planes**.
5. Solo hay **un plan vigente** en cada fecha; un plan nuevo cierra la vigencia del anterior.
6. Un plato puede ser **texto libre** o **referenciar una receta** del recetario, y esa receta debe existir.
7. Intercambio de grasa: **solo en la comida o en la cena, no en ambas** (validable cuando el usuario elabore su semana 2).
8. Una cantidad siempre es **mayor que cero** y se guarda de forma exacta (½ es exactamente ½). "Al gusto" o "una pizca" no son cantidades.
9. Una receta da **al menos una ración**, y un paso de preparación **no puede estar vacío**.
10. Una receta tiene **nombre** y **al menos un ingrediente**. Un ingrediente "al gusto" también cuenta.
11. Una receta **no repite ingredientes**: dos ingredientes con el mismo nombre (sin distinguir mayúsculas ni espacios) son el mismo, aunque lleven cantidades distintas.
12. Los **pasos de preparación son opcionales** y su orden es el de la lista: algunas recetas de los planes solo detallan ingredientes y raciones.
13. Un **plato nunca está vacío**: en la semana 1 tiene una descripción en texto libre; en la semana 2, un nombre corto (los ingredientes son opcionales). Cada ingrediente de un plato tiene nombre; la cantidad es opcional y se guarda tal como se escribió.
14. El **número de plan** es un entero positivo. Lo asigna la nutricionista y no es la identidad del plan.
15. Las **pautas fijas** son solo desayuno, media mañana y media tarde, y nunca están vacías: tienen una parte fija, opciones a elegir o ambas.
16. En un grupo **"elige N"**, N es al menos 1 y no mayor que el número de opciones, y ninguna opción se repite.
17. Un **intercambio** indica siempre su alimento y su ración. Las **reglas de los intercambios** se conservan con su texto y su orden; se harán cumplir en el nivel 8.

Esta lista crecerá a medida que avance el proyecto.
