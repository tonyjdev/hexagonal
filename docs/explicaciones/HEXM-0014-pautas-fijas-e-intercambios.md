# Explicación HEXM-0014: Pautas fijas e intercambios

## Explicación

Un plan nutricional no son solo las comidas y cenas de las dos semanas (HEXM-0013). Tiene además una parte que **es igual todos los días**, pero que **cambia de un plan a otro** (regla 4):

- las **pautas fijas** (desayuno, media mañana y media tarde), muchas con "elige N de las siguientes";
- la **tabla de intercambios**, que dice qué alimentos se pueden cambiar por otros, con unas reglas escritas por la nutricionista.

El problema tiene dos partes:

1. **Reglas que relacionan las piezas de un conjunto.** Una opción suelta ("Yogur natural desnatado") siempre es válida, pero un grupo "elige 4" con tres opciones no lo es. La validez está en el grupo, no en sus piezas.
2. **Reglas que hay que conservar pero todavía no aplicar.** "La grasa solo en la comida o en la cena, no en ambas" forma parte del plan y no se puede perder. Pero comprobarla contra un menú solo tiene sentido cuando el usuario elabore su semana 2 (nivel 8).

La idea de la solución:

- `OptionGroup` y `FixedMeal` son **value objects compuestos**: cada uno comprueba las reglas que solo se ven en el conjunto.
- `ExchangeTable` es una **colección agrupada por categoría** que **guarda** las reglas como texto, en su orden, sin intentar ejecutarlas.

## Lógica de negocio

- **Pautas fijas.** Son el desayuno, la media mañana y la media tarde. Se repiten todos los días y en las dos semanas. La comida y la cena **nunca** son pautas fijas: cambian cada día y van en el menú.
- **Una pauta tiene una parte fija, opciones a elegir, o ambas.** Por ejemplo:
  - Desayuno: *"Café con leche desnatada"* (se toma siempre) + *elegir 1*: *tostada de pan integral (40 g) con tomate* o *30 g de copos de avena*.
  - Media mañana: solo *elegir 2*: *1 pieza de fruta*, *yogur natural desnatado*, *20 g de nueces*.
  - Merienda: solo una parte fija: *"Infusión y 1 pieza de fruta"*.

  Una pauta vacía no dice qué comer, así que no puede existir.
- **"Elige N de las siguientes".** N es al menos 1 ("elige 0" no es una elección) y no puede superar las opciones que hay ("elige 4 de 3" es imposible). "Elige 2 de 2" sí es válido: en la práctica significa tomar las dos. Una opción no se repite: "Yogur natural" y "yogur  natural" son la misma, y repetirla haría que "elige 2" pudiera ser dos veces lo mismo.
- **Intercambios.** Cada intercambio pertenece a una categoría (hidratos, proteínas, vegetales, grasas) y dice un **alimento** y su **ración**: *arroz, 60 g en crudo*; *pan integral, 40 g*. Todos los de una categoría son equivalentes entre sí. La ración es obligatoria, porque una equivalencia sin cantidad no dice nada. Un mismo alimento puede estar en dos categorías: la legumbre cuenta como hidrato y como proteína.
- **Reglas de los intercambios.** Por ejemplo: *"La legumbre cuenta como ½ intercambio de proteína"* o *"La grasa solo en la comida o en la cena, no en ambas"*. Se conservan **con su texto y su orden**, tal como las escribió la nutricionista, y pueden cambiar en el plan siguiente.

## Decisiones de arquitectura

**Todo es dominio puro.** Las piezas nuevas (`MealOption`, `OptionGroup`, `FixedMeal`, `Exchange`, `ExchangeTable` y sus tres excepciones) viven en `MealPlanning\Domain`, sin Laravel, ni base de datos, ni HTTP. Los tests de arquitectura de `composer ci:check` lo garantizan. No hay puertos ni adaptadores nuevos: se usan llamando a sus constructores con nombre, que devuelven un objeto válido o lanzan un error de negocio en español.

**Cada regla en el objeto que la puede ver:**

```
 MealOption::of()        OptionGroup::choose()          FixedMeal::of()
 · texto no vacío    ─►  · 1 ≤ N ≤ opciones         ─►  · ¿es comida fija? (MealType::isFixed())
                         · sin opciones repetidas       · ¿tiene parte fija o algún grupo?
                                                                │
 Exchange::of()          ExchangeTable::of()                    ▼
 · alimento y ración ─►  · agrupar por categoría           FixedMeal válida
                         · reglas no vacías, en orden
```

- Una opción solo puede comprobar que tiene texto. Que N encaje con las opciones lo ve el grupo, y que la pauta no sea de comida o cena lo ve la pauta.
- Que el plan no repita pautas (dos desayunos) o que tenga que traer una tabla de intercambios son reglas sobre el plan entero. Las vigilará el agregado `NutritionPlan` (HEXM-0015), igual que `MenuWeek` vigila que no se repitan días.

**Agrupar por un enum sin filtrar el detalle.** PHP no acepta un enum como clave de array, así que `ExchangeTable` agrupa por su valor (`'carbohydrates'`, `'protein'`…). Ese detalle no sale de la clase: se pregunta con `exchangesIn(ExchangeCategory::Protein)`, que devuelve `[]` si el plan no trae esa categoría. Cada `Exchange` lleva su categoría dentro, así que la tabla recibe una lista plana, en el orden del documento, y la agrupa ella misma.

**Guardar una regla no es hacerla cumplir.** Las reglas se guardan como `list<string>` en `ExchangeTable`. El dominio garantiza que no se pierden, no se vacían y no se desordenan, pero no las interpreta. Cuando en el nivel 8 haya que comprobar "grasa solo en una comida", se decidirá con un caso real qué reglas pasan a ser código.

**`MealType::isFixed()` llega cuando hace falta.** El enum nació en HEXM-0012 con un comentario que decía que `isFixed()` se escribiría cuando alguna pieza lo necesitara. `FixedMeal` es esa pieza: el método nace con un uso real y un test.

## Alternativas descartadas

- **Cómo se haría en un Laravel clásico.** Tablas `fixed_meals`, `option_groups` y `meal_options` con modelos Eloquent, y la regla "N ≤ opciones" en un `FormRequest` o en un `saving` del modelo. Pero así un grupo podría existir a medias en memoria (con N antes que las opciones, o con una opción añadida después de validar). La regla se saltaría desde un *seeder* o un importador. Aquí un `OptionGroup` se crea entero y válido de una vez, y no cambia nunca.
- **Opciones como `list<string>`.** Habría servido para guardarlas, pero un string vacío sería una opción válida y la comparación "sin mayúsculas ni espacios" habría que repetirla en cada sitio. `MealOption` tiene esas reglas en un solo lugar.
- **La parte fija como lista estructurada de alimentos con cantidades.** Los planes la escriben como una frase ("Café con leche desnatada"), igual que los platos de la semana 1. Estructurarla ahora sería inventar un formato. Si la lista de la compra (nivel 9) lo necesita, se extraerá entonces.
- **"Mínimo y máximo" en lugar de un N exacto.** Los planes siempre dicen "elegir 2". Un "elige hasta 2" se añadiría con un caso real delante.
- **Ración con el `Quantity` del recetario.** Cruzaría la frontera entre contextos (los tests de arquitectura lo prohíben) y obligaría a interpretar textos como "1 pieza mediana". Se mantiene la decisión de HEXM-0012 para `DishIngredient`: cantidad en texto. La diferencia es que aquí la ración es obligatoria.
- **Reglas de intercambio como objetos con comportamiento** (por ejemplo, una clase `FatInOnlyOneMeal`). Sería adelantar el nivel 8 sin saber todavía qué reglas hay que ejecutar ni cómo se escriben en todos los planes. Hoy basta con no perderlas.
- **Un mapa `[categoría => intercambios]` como entrada de la tabla.** Obligaría al que llama a agrupar, y en PHP la clave no podría ser el enum. Es más sencillo que cada intercambio sepa su categoría.
- **Rechazar alimentos repetidos dentro de una categoría.** Es razonable, pero no está en los criterios de aceptación y no se ha pedido. Queda como ejercicio.

## Conceptos aplicados

| Concepto | Definición breve | Dónde verlo |
|---|---|---|
| Value object compuesto con su propia invariante | Un value object hecho de otros value objects que añade reglas sobre el conjunto (N frente a la lista). | `OptionGroup::choose()` |
| Composición de value objects | Una pauta contiene grupos que contienen opciones; todo inmutable y comparado por valor de arriba abajo. | `FixedMeal::equals()` y `OptionGroup::equals()` |
| Datos con reglas que todavía no se ejecutan | Conservar las reglas del negocio como datos (texto, orden) hasta que haya que aplicarlas. | `ExchangeTable` (bloque `CONCEPTO:`) y `rules()` |
| Colección inmutable agrupada por un enum | Agrupar por `->value` por dentro y preguntar por el enum por fuera. | `ExchangeTable::of()` y `exchangesIn()` |
| Regla del negocio en el enum | La pregunta "¿es una pauta fija?" en el propio tipo, con `match` sin `default`. | `MealType::isFixed()` |
| YAGNI | No escribir código hasta que haya un uso real. | Comentario de `MealType` (`isFixed()` aplazado de HEXM-0012 a HEXM-0014) |
| Frontera entre bounded contexts | No usar el `Quantity` del recetario: la ración es texto. | `Exchange` (bloque `DECISIÓN:`) |

## Diagramas

- [Pautas fijas e intercambios](../diagramas/HEXM-0014/pautas-fijas-e-intercambios.class.json) (`class`): ¿de qué piezas se compone lo que es común a todas las semanas del plan, y qué error lanza cada una?
- [¿Es válida esta pauta fija?](../diagramas/HEXM-0014/es-valida-esta-pauta-fija.workflow.json) (`workflow`): ¿qué reglas pasa una pauta del documento, en qué orden y con qué mensaje se rechaza cada fallo?

Para verlos: `cd app-laravel && composer docs:diagrams` y abrir `docs/diagramas/HEXM-0014/<slug>.html` en el navegador.
