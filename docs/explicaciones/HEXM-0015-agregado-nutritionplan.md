# Explicación HEXM-0015: Agregado NutritionPlan

## Explicación

En HEXM-0012 a 0014 se construyeron todas las piezas de un plan nutricional:

- los platos;
- los días y las dos semanas de menú;
- las pautas fijas;
- la tabla de intercambios.

Cada pieza vigila sus propias reglas, pero hay reglas que **no ve ninguna pieza por separado**:

- que un plan tenga exactamente la semana 1 y la semana 2;
- que empiece un lunes;
- que no traiga dos desayunos.

Hacía falta algo que reuniera las piezas y vigilara esas reglas sobre el conjunto. Ese algo es el **agregado `NutritionPlan`**.

El problema de fondo es una pregunta de diseño: **¿dónde está la frontera?** ¿Las semanas son parte del plan o son otra cosa con vida propia? ¿Y la vigencia, se guarda o se calcula?

La idea de la solución:

- **Un único agregado.** `NutritionPlan` es la raíz y la única puerta de entrada. Dentro viven las dos semanas, con sus días, las pautas fijas y la tabla de intercambios. Fuera quedan las recetas y los demás planes.
- **Vigencia derivada.** El plan solo guarda cuándo empieza; deja de estar vigente cuando empieza el siguiente.
- **Hoy es de solo lectura.** Se crea válido de una vez y se puede leer entero, pero nada se puede cambiar desde fuera.

## Lógica de negocio

- **Un plan es lo que entrega la nutricionista cada ~2 meses**, numerado (*plan 27*). Trae:
  - las pautas fijas, por ejemplo un desayuno de *café con leche desnatada* más *elegir 1: tostada integral con tomate o copos de avena*;
  - la tabla de intercambios, por ejemplo *arroz, 60 g en crudo*, con reglas como *"la grasa solo en la comida o en la cena"*;
  - las dos semanas de comidas y cenas.
- **Exactamente dos semanas que se alternan** (regla 1):
  - la 1 la hace la nutricionista (menús 1 a 6);
  - la 2 la hace el usuario (días 8 a 14).

  Un plan sin la semana 2 no se puede seguir: ¿qué se come la segunda semana? Y uno con dos semanas 1 no tiene sentido.
- **El plan empieza en lunes.** Las semanas del plan empiezan en lunes y la semana 1 empieza el mismo día que el plan. Si el plan 27 empezara un miércoles, el «menú 1 = lunes» caería a mitad de semana. Por eso un plan que empieza el 04/11/2026 se rechaza: es miércoles. Solo importa el día, no la hora.
- **Como mucho una pauta de cada tipo** (regla 18). Un plan con dos desayunos distintos no dice cuál seguir. Ninguna pauta es obligatoria: si el documento no trae media tarde, no la hay.
- **Vigencia** (reglas 5 y 19):
  1. El plan 27 está vigente desde su lunes de inicio hasta que empieza el 28.
  2. Cuando llega el 28, el 27 no se borra: queda como histórico («¿qué cenaba en el plan 25?»).
  3. Nadie tiene que cerrar el plan 27 a mano: lo cierra la llegada del siguiente.

## Decisiones de arquitectura

**Todo es dominio puro.** `NutritionPlan` e `InvalidNutritionPlan` viven en `MealPlanning\Domain`, sin Laravel, base de datos ni HTTP, y lo garantizan los tests de arquitectura de `composer ci:check`. La fecha es un `DateTimeImmutable` de PHP, no un Carbon, porque el núcleo solo usa PHP nativo y lo autorizado por ADR (ADR 0004). No hay puertos ni adaptadores nuevos. El repositorio de planes llegará en el nivel 5, y entonces guardará y cargará este agregado entero, nunca una semana suelta.

**La frontera del agregado (ADR 0010):**

```
 ┌──────────────────── NutritionPlan (frontera de consistencia) ────────────────────┐
 │  NutritionPlanId · PlanNumber · startsOn (lunes, a medianoche)                   │
 │  FixedMeal ×0..3 ─ OptionGroup ─ MealOption                                      │
 │  ExchangeTable ─ Exchange                                                        │
 │  MenuWeek ×2 ─ MenuDay ×6 ─ Dish ─ DishIngredient                                │
 └──────────────────────────────────────────────────────────────────────────────────┘
        │ (nivel 6) solo RecipeId                 │ (nivel 5) consulta entre planes
        ▼                                         ▼
   Recipe (otro agregado, otro contexto)     otros NutritionPlan: ¿cuál está vigente?
```

- **Dentro va lo que comparte una regla que debe cumplirse siempre.**
  - «Exactamente la semana 1 y la 2» mira las dos semanas a la vez.
  - En el nivel 8, «la semana 2 respeta los intercambios» mirará la semana y la tabla a la vez.

  Si las semanas fueran un agregado aparte, ninguna de esas reglas tendría un dueño que pudiera garantizarla siempre.
- **Fuera va lo que tiene vida propia o afecta a varios agregados.**
  - Una receta existe aunque no esté en ningún plan, y la gestiona otro contexto: se referenciará por id.
  - «Solo un plan vigente» compara planes entre sí, y un plan no puede vigilar a los demás: se resolverá consultando el repositorio en el nivel 5.
- **El tamaño no es un problema.** Un plan son 12 días, unas pocas pautas y una tabla de intercambios, y el calendario del nivel 4 lo necesitará entero de todos modos.

**Cada regla en el objeto que la puede ver**, igual que en HEXM-0013 y 0014. Las piezas llegan ya válidas: una `MenuWeek` ya tiene 6 días y es la 1 o la 2, y una `FixedMeal` ya es de una comida fija y no está vacía. El agregado solo añade las reglas del **conjunto**, en este orden:

1. La fecha, a medianoche, ¿cae en lunes?
2. ¿Se repite alguna pauta fija?
3. ¿Se repite alguna semana?
4. ¿Están la 1 y la 2?

Como `MenuWeek` solo admite los números 1 y 2, comprobar primero las repetidas basta para saber que nunca hay más de dos. Las semanas y las pautas se guardan en su orden canónico (semana 1 y luego 2; desayuno, media mañana, media tarde), lleguen como lleguen.

**Inmutable por fuera y por dentro.**
- La clase es `final readonly`: ni siquiera ella misma puede reasignar una propiedad después de construirse.
- Los getters devuelven value objects inmutables, `MenuWeek` (también `readonly`) o copias de los arrays internos, porque en PHP los arrays se copian por valor.
- La fecha es un `DateTimeImmutable`, así que «cambiarla» devuelve otra.

Cuando haga falta editar la semana 2 (nivel 8), llegará un método de negocio en la raíz que vuelva a comprobar las invariantes, no un setter.

**`Weekday::fromDate()`** convierte una fecha en día de la semana. Nace aquí porque el plan lo necesita para comprobar el lunes, y lo reutilizará el calendario del nivel 4. Esconde el detalle técnico (el formato `'N'` de PHP y la numeración ISO) tras una pregunta del negocio.

## Alternativas descartadas

- **Cómo se haría en un Laravel clásico.**
  - Habría un modelo Eloquent `NutritionPlan` con `hasMany(MenuWeek::class)`, y cada semana se guardaría por su cuenta (`$plan->weeks()->create(...)`).
  - La regla de las dos semanas viviría, como mucho, en un `FormRequest`, y un *seeder*, un importador o un `$week->delete()` podrían dejar un plan con una sola semana.
  - La fecha sería un Carbon mutable: `$plan->starts_on->addDay()` cambia la fecha del modelo sin pasar por ninguna regla.

  Aquí el plan se crea entero y válido o no se crea, y no se puede modificar por partes.
- **Semanas como agregados aparte**, con su propio id y repositorio. Permitiría cargar una semana sin el resto, pero la regla «exactamente dos semanas» quedaría fuera de cualquier agregado y solo se cumpliría «al final» (consistencia eventual). Con un plan tan pequeño, no compensa.
- **Guardar una fecha de fin (`endsOn`) y un método `close()`.** Es más explícito, pero duplica un dato, porque el fin de un plan es el inicio del siguiente. Habría que mantener las dos copias coherentes: si se corrige la fecha de inicio del plan 28, también habría que corregir el fin del 27. Se revisará si aparece el caso de cerrar un plan sin que empiece otro, como una pausa (ADR 0010).
- **Exigir que la semana 1 sea de la nutricionista y la 2 del usuario.** Hoy siempre es así, pero es una costumbre del documento, no una regla que haya pedido el negocio, y los constructores de formato de `MenuWeek` ya fijan esa pareja. Se mantiene la decisión de HEXM-0013.
- **Exigir las tres pautas fijas.** No es una regla del negocio: un plan puede no traer media tarde.
- **Un constructor público o setters «para los tests».** Romperían la garantía de que todo `NutritionPlan` en memoria es válido. Los tests usan `create()`, como cualquier otro código.
- **Aceptar `DateTimeInterface` en `create()`.** Admitiría un `DateTime` mutable que alguien podría cambiar por fuera. Se pide `DateTimeImmutable`. `Weekday::fromDate()` sí acepta la interfaz, porque solo lee la fecha y no la guarda.

## Conceptos aplicados

| Concepto | Definición breve | Dónde verlo |
|---|---|---|
| Límites de un agregado | Qué se valida y se guarda junto (consistencia) y qué se referencia por id o se resuelve entre agregados (tamaño). | Bloque `CONCEPTO:` de `NutritionPlan` y ADR 0010 |
| Raíz de agregado | La única puerta de entrada: todo se crea y se lee a través de ella. | `NutritionPlan::create()`, `week()` y `fixedMeal()` |
| Invariantes sobre el conjunto | Reglas que solo ve el agregado: cardinalidad (2 semanas), unicidad (semanas y pautas) y orden canónico. | `bothWeeksInOrder()` y `fixedMealsInDayOrder()` |
| Dato derivado frente a dato guardado | La vigencia se calcula a partir de la fecha de inicio del siguiente plan: una sola fuente de verdad. | Bloque `CONCEPTO:` de vigencia en `NutritionPlan` y regla 19 de `DOMINIO.md` |
| Tiempo en el dominio con PHP nativo | `DateTimeImmutable` sin Carbon, normalizado al día. Prepara el puerto `Clock` del nivel 4. | `NutritionPlan::create()` y `Weekday::fromDate()` |
| Entidad inmutable (YAGNI) | Una entidad que se compara por id pero que hoy nadie modifica: `readonly`, sin métodos de cambio. | `final readonly class NutritionPlan` y `equals()` |
| No exponer el interior | Getters que devuelven objetos inmutables o copias de arrays. | `weeks()`, `fixedMeals()` y el test «cannot be changed through what it returns» |
| `#[\NoDiscard]` y cast `(void)` (PHP 8.5) | PHP avisa si se tira el resultado de `modify()`; `(void)` lo hace a propósito. | `NutritionPlanTest.php` |

## Diagramas

- [Agregado NutritionPlan](../diagramas/HEXM-0015/agregado-nutritionplan.class.json) (`class`): ¿qué contiene la frontera de consistencia del plan, qué value objects usa la raíz y qué error lanza?
- [¿Es válido este plan?](../diagramas/HEXM-0015/es-valido-este-plan.workflow.json) (`workflow`): ¿qué invariantes comprueba `NutritionPlan::create()`, en qué orden y con qué mensaje se rechaza cada fallo?

Para verlos: `cd app-laravel && composer docs:diagrams` y abrir `docs/diagramas/HEXM-0015/<slug>.html` en el navegador.
