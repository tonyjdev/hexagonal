# Explicación HEXM-0012: Value objects de los planes

## Explicación

Con esta tarea arranca el segundo *bounded context*, `MealPlanning`, que acabará respondiendo a "¿qué me toca comer hoy?". Antes de construir los días, las semanas y el plan (HEXM-0013 a 0015) hacen falta las piezas pequeñas con las que se montan: el identificador y el número del plan, el vocabulario cerrado del negocio (tipos de comida, origen de la semana, categorías de intercambio) y, sobre todo, el **plato**.

La idea de la solución es la misma que en el recetario: cada concepto del negocio es un **value object** que no puede existir en un estado inválido. A eso se suman dos novedades propias de tener ya dos contextos:

1. **Qué se comparte entre contextos y qué no.** El generador de UUID v7 vivía dentro de `RecipeId`. Ahora también lo necesita `NutritionPlanId`, así que se extrae al *shared kernel* (`Shared\Domain\Uuid`), como ya dejó previsto el ADR 0005. En cambio, la cantidad de un ingrediente (`Quantity`) **no** se comparte: el plato la guarda como texto.
2. **Un mismo concepto con dos formatos.** La nutricionista escribe los platos de la semana 1 en texto libre. El usuario escribe los de la semana 2 con un nombre corto y una lista de ingredientes. Un único `Dish` admite los dos formatos, cada uno con su constructor con nombre.

## Lógica de negocio

- **Número de plan.** La nutricionista numera sus planes ("plan 27"). El número siempre es positivo: un "plan 0" o un "plan -3" no existen. Pero el número **no identifica** el plan dentro de la aplicación: lo asigna una persona fuera del sistema, que podría repetirlo o equivocarse. Por eso el plan tiene además su propio identificador.
- **Las cinco comidas del día**, en el orden en que se comen: desayuno, media mañana, comida, media tarde y cena. Las tres "pautas fijas" (desayuno, media mañana y media tarde) se repiten todos los días. La comida y la cena cambian cada día según el menú.
- **Quién elabora cada semana.** La semana 1 la hace la nutricionista y la semana 2, el propio usuario ("elaboración propia"). El origen marca el formato de los platos y, más adelante, qué reglas se validan. Por ejemplo, "la grasa solo en la comida o en la cena" se comprobará cuando el usuario elabore su semana 2.
- **Categorías de intercambio**: hidratos, proteínas, vegetales y grasas. "60 g de arroz" se puede cambiar por "80 g de pasta" porque los dos cuentan como un intercambio de hidratos.
- **El plato**, en sus dos formatos reales:
  - Semana 1: *"Lentejas (½ bote) con pisto de calabacín, cebolla y tomate"*. Es una sola frase, con las cantidades mezcladas en el texto.
  - Semana 2: *"Pasta con salmón y pesto"* con su lista: pasta 80 g, salmón 100 g, pesto.
  - Regla: **un plato nunca está vacío**. En la semana 1 tiene que haber una descripción; en la semana 2, un nombre. Los ingredientes son opcionales: un plato propio como "Sobras del sábado" puede no detallarlos. Cada ingrediente tiene nombre, y su cantidad es opcional ("sal").

## Decisiones de arquitectura

**Todo es dominio puro.** Todas las piezas nuevas viven en `MealPlanning\Domain` o en `Shared\Domain`: no hay casos de uso, ni base de datos, ni HTTP. Por eso se prueban con tests unitarios sin arrancar Laravel, y los tests de arquitectura (`composer ci:check`) garantizan que no usan nada de Laravel ni del recetario.

**`Uuid` va al shared kernel, `Quantity` no.** El criterio para extraer algo a `Shared` es que sea **común de verdad**, no que se parezca:

```
 Recipes\Domain              Shared\Domain             MealPlanning\Domain
 ┌──────────────┐  envuelve  ┌─────────────────┐  envuelve  ┌─────────────────┐
 │ RecipeId     │──────────► │ Uuid            │ ◄──────────│ NutritionPlanId │
 │ Quantity ✗   │            │ DomainException │            │ DishIngredient  │
 └──────────────┘            └─────────────────┘            └─────────────────┘
          (ninguna flecha entre Recipes y MealPlanning)
```

- Un UUID es pura técnica (128 bits con un formato estándar) y no tiene significado de negocio: no puede querer decir una cosa en el recetario y otra en los planes. Los dos contextos repetirían exactamente las mismas 20 líneas de manipulación de bits. Se extrae en el **segundo** uso, no en el primero, como pedía el ADR 0005: con un solo uso habría sido una abstracción prematura.
- Una cantidad sí es un concepto del negocio, y cada contexto la ve a su manera. En el recetario es una medida exacta para cocinar (la fracción 3/2 más una unidad). En un plan es lo que alguien apuntó en su documento ("1 puñado", "½ bote"). Compartirla ataría los dos contextos a un único modelo de cantidad.

**Composición, no herencia.** `RecipeId` y `NutritionPlanId` *tienen* un `Uuid` dentro; no *son* un `Uuid`. Así siguen siendo tipos distintos, y PHPStan rechaza pasar el identificador de un plan donde se espera el de una receta. Por eso `Uuid` no lanza excepciones: `tryFromString()` devuelve `null`, y cada identificador lanza su propio error, con un mensaje que habla del negocio ("no es un identificador de **plan** válido"). `RecipeId` mantiene su API y su excepción, y sus tests pasan sin haberlos tocado: es la prueba de que el refactor no ha cambiado su comportamiento.

**Los ingredientes de un plato llevan la cantidad en texto.** Esta decisión (documentada en `DishIngredient` y en el glosario) respeta la frontera entre contextos, porque el núcleo de `MealPlanning` no puede importar nada de `Recipes`. Separa el **nombre** de la cantidad, lo que ya deja preparado agrupar por ingrediente en la lista de la compra (nivel 9). Y no adelanta complejidad: si esa lista necesita *sumar* cantidades, se decidirá entonces, con requisitos reales, entre un `Quantity` propio o uno compartido. No hay ADR porque esta decisión no modifica el shared kernel.

**Un solo `Dish` con dos constructores con nombre.** `Dish::described()` (semana 1) y `Dish::named()` (semana 2) se leen en el lenguaje del negocio y cada uno exige solo lo que trae su formato. La invariante "nunca vacío" queda en un único sitio. El plato no guarda el `WeekSource`: el origen es de la **semana**, y lo sabrá `MenuWeek`.

**Enums con etiqueta y `match` exhaustivo.** `MealType`, `WeekSource` y `ExchangeCategory` siguen el patrón de `Unit`. El valor en inglés (`'lunch'`) es el contrato con la base de datos y la API, y `label()` devuelve el texto en español. Como `label()` usa un `match` sin `default`, si alguien añade un caso sin etiqueta, PHPStan falla con `match.unhandled`. Se comprobó durante la tarea añadiendo un caso temporal a `MealType`.

## Alternativas descartadas

- **En un Laravel clásico**, el plan sería un modelo Eloquent con columnas `number` (int), `meal_type` (string) y un JSON de ingredientes, y las reglas ("número positivo", "plato no vacío") estarían repartidas entre Form Requests y `$casts`. Aquí las reglas viven en los propios tipos: si tienes un `PlanNumber`, es válido, venga de HTTP, de un Excel o de un test.
- **Usar el número de plan como identificador.** Lo asigna la nutricionista fuera del sistema, así que no controlamos que sea único ni estable.
- **Un identificador genérico `Id` o herencia `RecipeId extends Uuid`.** Con herencia, los dos identificadores serían intercambiables allí donde se espere un `Uuid`, y perderían su error de dominio propio.
- **Otras opciones para los ingredientes del plato:**
  - Una línea de texto por ingrediente ("80 g de pasta"): no separa el nombre, que es lo que necesitará la lista de la compra.
  - Duplicar un `Quantity` propio ahora: es complejidad que nadie usa todavía.
  - Mover `Quantity` y `Unit` al shared kernel: acopla los dos contextos a un modelo de cantidad y exigiría un ADR.
- **Dos clases de plato (`FreeTextDish` y `StructuredDish`) con una interfaz.** Obligaría a preguntar `instanceof` en el día, en el calendario o en la lista de la compra, cuando todos tratan los platos igual.

## Conceptos aplicados

| Concepto | Definición breve | Dónde verlo |
|---|---|---|
| Segundo *bounded context* | Mismo patrón (value objects, excepciones de dominio) con un vocabulario propio y sin depender del otro contexto. | `app-laravel/src/MealPlanning/Domain/` |
| *Shared kernel* | Pequeño trozo de código común que varios contextos acuerdan compartir; solo lo que es común de verdad. | `app-laravel/src/Shared/Domain/Uuid.php`, `app-laravel/src/Shared/README.md` |
| Composición frente a herencia | Un identificador *tiene* un `Uuid` y le delega el trabajo técnico, pero sigue siendo un tipo propio. | `RecipeId.php`, `NutritionPlanId.php` |
| `try` + nullable | Devolver `null` en vez de lanzar, para que quien llama elija su propio error de dominio. | `Uuid::tryFromString()` y `?? throw` en `fromString()` |
| Constructores con nombre | Formas de crear un objeto que se leen en el lenguaje del negocio y exigen lo justo. | `Dish::described()`, `Dish::named()` |
| *Primitive obsession* | Usar `int` o `string` para un concepto del negocio; se evita con un value object que contiene la regla. | `PlanNumber.php` |
| Enum con `match` exhaustivo | Conjunto cerrado de valores; PHPStan detecta un caso sin tratar. | `MealType.php`, `WeekSource.php`, `ExchangeCategory.php` |
| Frontera entre contextos | El núcleo de un contexto no importa otro; lo comprueba un test de arquitectura. | `DishIngredient.php`, `tests/Architecture/HexagonalLayersTest.php` |

## Diagramas

- [Piezas del plan](../diagramas/HEXM-0012/piezas-del-plan.class.json) (`class`): ¿con qué value objects y enums arranca `MealPlanning`, cómo admite `Dish` los dos formatos y qué errores lanza cada pieza?
- [Shared kernel entre dos contextos](../diagramas/HEXM-0012/shared-kernel-entre-contextos.architecture.json) (`architecture`): ¿qué comparten `Recipes` y `MealPlanning` (`Uuid`, `DomainException`) y qué se queda en cada contexto (`Quantity`)?

Para verlos: `cd app-laravel && composer docs:diagrams` y abrir `docs/diagramas/HEXM-0012/piezas-del-plan.html` y `docs/diagramas/HEXM-0012/shared-kernel-entre-contextos.html`.
