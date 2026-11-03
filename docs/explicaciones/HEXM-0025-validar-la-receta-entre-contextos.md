# Explicación HEXM-0025: Validar la receta entre contextos

## Explicación

Desde HEXM-0024 un plato puede apuntar a una receta del recetario, pero nadie comprobaba que esa receta existiera. Se podía dar de alta un plan cuyo «Guiso de alubias y pollo» apuntara a un identificador inventado. La regla 6 lo prohíbe: **si un plato apunta a una receta, la receta tiene que existir**.

La dificultad no es la comprobación en sí, sino **quién pregunta a quién**. El núcleo de `MealPlanning` no puede importar nada de `Recipes`, y los tests de arquitectura lo comprueban. ¿Cómo sabe entonces el alta de un plan si una receta existe?

La idea de la solución:

- `MealPlanning` **define su propia pregunta**, un puerto `RecipeCatalog` con `exists(RecipeReference): bool`, escrita en su idioma;
- un adaptador de su infraestructura, `RecipesContextRecipeCatalog`, **traduce** esa pregunta a la capa de aplicación de `Recipes` (`GetRecipe`) y la respuesta de vuelta a un `bool`. Es la **capa anticorrupción**: el modelo del otro contexto no se cuela en el propio;
- un **test de arquitectura nuevo** fija por dónde puede entrar un contexto en otro: solo por su capa de aplicación.

## Lógica de negocio

- **La receta de un plato tiene que existir en el recetario.** Si el menú 6 de la semana 1 dice que su comida, «Guiso de alubias y pollo», es la receta `0190a…`, esa receta debe estar en el recetario. Si no está, el alta se rechaza con un mensaje en español que nombra el plato: «El plato «Guiso de alubias y pollo» apunta a una receta que no existe en el recetario («0190a…»)».
- **Todo o nada.** Si una sola receta no existe, no se guarda nada del plan. No hay medio plan guardado con platos que apunten al vacío.
- **Un plato sin receta sigue siendo válido.** La cena del mismo menú, «Ensalada de quinoa con queso fresco», no apunta a ninguna receta y no hace falta comprobar nada.
- **Las dos semanas pueden llevar recetas.** En la semana 1 (la de la nutricionista) el plato es una frase, y la receta se indica al lado: `lunch_recipe_id`, `dinner_recipe_id`. En la semana 2 (la del usuario) el plato ya es una ficha con nombre e ingredientes, y la receta va dentro: `recipe_id`.
- **Un identificador que ni siquiera tiene forma de identificador** («guiso-de-alubias») se rechaza antes de mirar el recetario: «"guiso-de-alubias" no es una referencia de receta válida.».
- **Primero las reglas del plan, después el recetario.** Un plan que empieza en martes se rechaza por eso, sin llegar a mirar sus recetas: el error que ve el usuario es el más básico.
- **La comprobación es al dar de alta.** Qué pasa si después se borra una receta que un plan usa queda para HEXM-0026 y HEXM-0027.

## Decisiones de arquitectura

### El recorrido de una petición

```
POST /api/nutrition-plans  {menu 6: lunch "Guiso…", lunch_recipe_id "0190a…"}
  │
  ▼ MealPlanning\Infrastructure\Http
RegisterNutritionPlanRequest     solo forma: recipe_id es texto opcional
RegisterNutritionPlanController  → RegisterNutritionPlanCommand
  │
  ▼ MealPlanning\Application
RegisterNutritionPlan
  1. NutritionPlan::create(…)            ← dominio: reglas del plan; RecipeReference::fromString()
  2. RecipeCatalog::exists(ref) ×N       ← una vez por receta distinta
  3. NutritionPlanRepository::save(plan) ← solo si todo lo anterior pasa
        │
        │ puerto (interfaz de MealPlanning)
        ▼ MealPlanning\Infrastructure\Recipes
RecipesContextRecipeCatalog   ← capa anticorrupción
  RecipeReference → '0190a…'   ──►  Recipes\Application\GetRecipe
  true            ◄── RecipeView
  false           ◄── RecipeNotFound
```

### Un puerto propio, en `Application` (ADR 0017)

- **El puerto es de `MealPlanning`, no de `Recipes`.** Habla con `RecipeReference` (el tipo propio de HEXM-0024), no con `RecipeId` ni con `RecipeView`. La dependencia se invierte: quien necesita algo define la interfaz, y el de fuera se adapta. Es lo mismo que con la base de datos (`NutritionPlanRepository`), pero el «sistema externo» es otro contexto de la misma aplicación.
- **Vive en `Application` y no en `Domain`.** Solo lo usa el caso de uso del alta. Preguntar por el estado de otro sistema es orquestación, igual que pedir la fecha de hoy al `Clock` (ADR 0012). El dominio (`Dish`, `NutritionPlan`) sigue puro: recibe la referencia y no pregunta nada a nadie. `NutritionPlanRepository` sí está en `Domain`, porque guarda los agregados del propio contexto y su contrato incluye reglas entre planes (ADR 0011).
- **Devuelve un `bool`, lo mínimo.** El plan solo necesita saber si la receta existe. Devolver la receta invitaría a copiar sus ingredientes en el plato (eso es del nivel 10) y ataría los dos modelos.

### El error: `UnknownRecipe`, un 422 y no un 404

- Hereda de `DomainException`, como «un plan empieza siempre en lunes». Lo que falla es un **dato del plan** que se está dando de alta, no un recurso que se busca: un 404 diría «el plan que pides no existe», y no es el caso.
- Vive en `Application`, junto a su puerto, porque el dominio no puede detectarlo: no sabe qué recetas hay.

### El caso de uso: construir, comprobar, guardar

- Primero se construye el plan: el dominio comprueba las reglas que no cuestan nada, en memoria.
- Después se recorren los platos del **agregado ya construido**, que es lo que se va a guardar, y se pregunta **una vez por receta distinta**: si el mismo guiso aparece en la comida y en la cena, se pregunta una vez.
- Solo si todo pasa, `save()`. Como guardar es una única fila (ADR 0013), el alta sigue siendo atómica.

### La capa anticorrupción: `RecipesContextRecipeCatalog`

- Está en `MealPlanning\Infrastructure\Recipes`, el subespacio de lo que habla con ese contexto.
- **Entra por la capa de aplicación de `Recipes`**, que es su API pública: casos de uso y DTO. Usar su `RecipeRepository` o la tabla `recipes` se saltaría sus reglas y acoplaría `MealPlanning` a cómo se guardan las recetas.
- **Reutiliza `GetRecipe`** en lugar de añadir una consulta «¿existe?» al recetario. Carga la receta entera para responder sí o no, pero un plan se da de alta cada dos meses con pocas recetas: no compensa tocar el otro contexto. Si algún día pesa, se añade `RecipeExists` en `Recipes\Application` y solo cambia el adaptador.
- **No captura `InvalidRecipeId`**: `RecipeReference` y `RecipeId` validan igual (los dos sobre `Shared\Domain\Uuid`), así que un id mal formado nunca llega aquí. Capturarlo, además, obligaría a usar `Recipes\Domain`, justo lo que se prohíbe.
- Lo enchufa `MealPlanningServiceProvider`. El `GetRecipe` que necesita lo construye el contenedor con el *binding* de `RecipesServiceProvider`: los *providers* no se conocen entre sí.
- **Dos adaptadores, un contrato**: `InMemoryRecipeCatalog` (las recetas que «existen» se pasan al construirlo) para probar el caso de uso sin Laravel ni recetario, y el real. Los dos pasan los mismos tests de contrato.

### La frontera, comprobada

Los tests de arquitectura ya impedían que el núcleo (`Domain` y `Application`) de un contexto usara otro. Ahora, además, **la infraestructura de un contexto solo puede usar la capa de aplicación de otro**:

```
MealPlanning\Infrastructure ──► Recipes\Application     ✔
MealPlanning\Infrastructure ──► Recipes\Domain          ✘
MealPlanning\Infrastructure ──► Recipes\Infrastructure  ✘
```

Se escribió para todos los pares de contextos, no solo para `MealPlanning → Recipes`. Se comprobó que funciona rompiéndolo: el adaptador importando `Recipes\Domain\InvalidRecipeId` hace fallar el test.

### El contrato de la API (amplía el ADR 0014)

- Semana 1: claves **hermanas** del texto del plato, `lunch_recipe_id` y `dinner_recipe_id`. Allí un plato es solo una frase y no tiene dónde llevar la receta.
- Semana 2: `recipe_id` dentro de cada plato.
- Todas son opcionales: el JSON anterior sigue valiendo tal cual, sin tocar el *fixture* ni los clientes.
- La *Form Request* solo comprueba que sean texto. Que sea un UUID lo dice el dominio y que exista, el puerto. Los dos errores son 422 en español y llegan igual a quien no pase por HTTP (el importador del nivel 7).

## Alternativas descartadas

- **Un Laravel clásico**: en la *Form Request*, `'recipe_id' => 'exists:recipes,id'`, o en el modelo del plato, `Recipe::find($id)`. Es una línea, pero `MealPlanning` consultaría directamente la tabla de **otro contexto**: se salta las reglas del recetario, depende de cómo guarda sus datos (si mañana es un servicio externo, se rompe) y deja la regla en la frontera HTTP, donde el importador del nivel 7 no la vería.
- **Llamar a `GetRecipe` desde el caso de uso**: más corto, pero el núcleo de `MealPlanning` dependería de `Recipes`, y su test unitario necesitaría el recetario. Los tests de arquitectura lo prohíben.
- **El puerto en `Domain`**: lo pondría junto a `Dish`, pero el dominio nunca lo llamaría; solo la aplicación pregunta.
- **Que el puerto devuelva la receta (o un DTO con sus datos)**: más de lo que el plan necesita, y una invitación a copiar datos de un contexto en otro.
- **Una consulta nueva `RecipeExists` en `Recipes`**: más eficiente, pero toca el otro contexto sin necesidad real. Queda como ejercicio y como plan si un día hace falta.
- **El contrato de la semana 1 con objetos** (`"lunch": {"description", "recipe_id"}`), siempre o como alternativa al texto: más uniforme con la semana 2, pero rompía el contrato o lo hacía polimórfico. Se eligieron las claves hermanas, compatibles con lo que ya existe.
- **La regla `uuid` en la *Form Request***: el formato del id ya lo valida el dominio, con su mensaje; repetirlo daría dos errores distintos (de forma y de dominio) para lo mismo.

## Conceptos aplicados

| Concepto | Definición breve | Dónde verlo |
|---|---|---|
| Capa anticorrupción (ACL) | Un adaptador que traduce el modelo de otro contexto al propio, para que no se cuele en él. | `app-laravel/src/MealPlanning/Infrastructure/Recipes/RecipesContextRecipeCatalog.php` |
| Puerto propio hacia otro contexto | El contexto que necesita algo define la interfaz en su idioma; el otro se adapta. | `app-laravel/src/MealPlanning/Application/RecipeCatalog.php` |
| Inversión de dependencias | El núcleo depende de una interfaz suya; la implementación concreta está fuera y se enchufa en la raíz de composición. | `MealPlanningServiceProvider` |
| Error de negocio frente a «no encontrado» | Un dato inválido de lo que se da de alta es un 422, aunque «no se encuentre» algo. | `app-laravel/src/MealPlanning/Application/UnknownRecipe.php` |
| Validar contra fuera, antes de guardar | Primero las reglas en memoria; después la consulta externa; por último, guardar. | `RegisterNutritionPlan::ensureRecipesExist()` |
| *Fake* y tests de contrato | Un adaptador en memoria que cumple los mismos tests que el real. | `InMemoryRecipeCatalog`, `tests/Feature/MealPlanning/Infrastructure/RecipeCatalogContractTest.php` |
| Espía (*spy*) | Un doble de test que registra cómo se le llama. | `tests/Unit/MealPlanning/Application/RegisterNutritionPlanTest.php` (una pregunta por receta) |
| Tests de arquitectura entre contextos | Fijan por qué capa puede entrar un contexto en otro. | `app-laravel/tests/Architecture/HexagonalLayersTest.php` (`forbiddenCrossContextLayers()`) |
| Evolución compatible de un contrato | Claves nuevas opcionales: lo anterior sigue valiendo. | `RegisterNutritionPlanRequest`, ADR 0017 |

## Diagramas

- [Capa anticorrupción](../diagramas/HEXM-0025/capa-anticorrupcion.architecture.json) (`architecture`): ¿cómo pregunta `MealPlanning` al recetario sin depender de él? Muestra el caso de uso, su puerto `RecipeCatalog` en `Application`, los dos adaptadores (memoria y la capa anticorrupción), `GetRecipe` como única puerta de `Recipes` y el test de arquitectura que prohíbe entrar en `Recipes\Domain`.
- [Alta de un plan con recetas](../diagramas/HEXM-0025/alta-con-receta.sequence.json) (`sequence`): ¿qué pasa en cada caso del alta? Muestra los tres caminos de `POST /api/nutrition-plans`: receta existente (201), receta inexistente (`RecipeNotFound` → `false` → `UnknownRecipe`, 422 sin guardar) e id mal formado (422 sin llegar a preguntar).

Para verlos: `cd app-laravel && composer docs:diagrams` y abrir `docs/diagramas/HEXM-0025/capa-anticorrupcion.html` y `docs/diagramas/HEXM-0025/alta-con-receta.html` en el navegador.
