# Explicación HEXM-0030: Leer el Excel de la semana 2

## Explicación

Un plan nutricional llega en dos documentos. El Excel de la nutricionista trae el número, las pautas fijas, los intercambios y la semana 1, y ya lo leía el servicio `importer` (HEXM-0029). La semana 2 la elabora el usuario en su propio Excel, con otro formato: una fila por comida, con el día (8-14), el tipo (Comida o Cena), el nombre del plato y sus ingredientes en una sola celda, separados por comas.

Esta tarea enseña al servicio a leer ese **segundo formato** y a devolverlo con la forma de `self_week` del contrato del alta de un plan (ADR 0014). Es la pieza que faltaba para que Laravel pueda juntar los dos documentos (HEXM-0031 y siguientes).

Había tres preguntas abiertas:

1. El Excel tiene dos hojas, «Detalle» y «Resumen», y las dos dicen los nombres de los platos. ¿Qué papel tiene cada una?
2. Los ingredientes vienen en texto libre («⅓ vaso de arroz en crudo, 2 filetes de merluza, tomate»). ¿Hasta dónde se traducen?
3. ¿Cómo convive un segundo lector con el primero sin duplicar código ni mezclarlos?

## Lógica de negocio

**La semana 2 en el dominio.** Una semana tiene 6 días con menú y el sábado es libre (reglas 2 y 3). En el documento del usuario los días van del 8 al 14: el 8 es el lunes y el 13 es el sábado, que se deja vacío. Cada día con menú tiene comida y cena. Un plato de la semana 2 tiene un nombre corto y, opcionalmente, ingredientes, cada uno con un nombre y una cantidad opcional en texto (regla 13, `DishIngredient`). El contrato lo refleja: `self_week: [{day, lunch: {name, ingredients: [{name, quantity}]}, dinner}]`, con el 13 a `null`.

**«Detalle» es la fuente; «Resumen» se comprueba.** Las dos hojas están escritas a mano, sin fórmulas. «Resumen» es una cuadrícula cómoda para ver la semana de un vistazo, pero repite los nombres de «Detalle» y puede quedarse desfasada si se corrige un plato en una hoja y se olvida la otra. Si no coinciden, el lector no puede saber cuál es la buena. Por eso:

- los platos se leen de «Detalle», la única hoja que tiene los ingredientes;
- en «Resumen» se comprueba que cada día y cada comida digan el mismo plato, sin distinguir mayúsculas, tildes ni espacios de más;
- si no coinciden, la respuesta es un 422 que señala las dos celdas: «Hoja «Resumen», celda C3: el plato «Crema de calabaza» no coincide con el de la hoja «Detalle» (celda C5: «Crema de verduras con queso»)».

**Ingredientes: texto entero, sin separar la cantidad.** Cada trozo de la celda es un ingrediente con su texto completo y `quantity: null`:

| En la celda | Ingredientes |
|---|---|
| «⅓ vaso de arroz en crudo, 2 filetes de merluza, tomate.» | «⅓ vaso de arroz en crudo», «2 filetes de merluza», «tomate» |
| «Pasta (¾ vaso en crudo, integral), 2 cdas. de pesto» | «Pasta (¾ vaso en crudo, integral)», «2 cdas. de pesto» (la coma del paréntesis no parte) |
| «1,5 kg de patatas, perejil» | «1,5 kg de patatas», «perejil» (la coma decimal no lleva espacio detrás) |
| «2 huevos. Chocolate 85%.» | «2 huevos», «Chocolate 85%» (un punto que acaba una frase también parte; el de «cda.» no) |

¿Por qué no separar la cantidad? Porque los casos reales lo impiden con reglas fijas. «tomate» no tiene cantidad. «1 lata y media de atún» la tiene repartida. «6-8 tomates cherry» es un intervalo. «Media tarrina de queso fresco de cabra (125 g)» lleva dos cantidades. Una expresión regular acertaría en los fáciles y se equivocaría en silencio en los demás, y un dato equivocado con aspecto de correcto es peor que un texto entero. Separarlas es trabajo de un LLM con *structured outputs* (nivel 9, `IngredientExtractor`). El campo `quantity` ya se envía para que, cuando llegue ese nivel, solo cambie su valor y no la forma del contrato.

**La forma de la semana, comprobada donde se puede señalar.** El lector rechaza, cada uno con su celda:

- un día fuera del 8-14;
- un tipo que no es «Comida» ni «Cena» («el tipo «Merienda» no es «Comida» ni «Cena»»);
- una comida repetida («la comida del día 8 está repetida»);
- una comida que falta;
- un plato en el día 13 («el día 13 es el sábado libre: no puede tener plato»).

Las filas del 13 pueden estar vacías o no existir, y un plato sin ingredientes es válido. Laravel vuelve a comprobar las reglas de la semana en el dominio (`MenuWeek`, `MenuDay`), pero sin saber de hojas ni de celdas: comprobarlo aquí es para dar un mensaje útil, no para sustituir al dominio.

## Decisiones de arquitectura

### Dónde encaja

El servicio `importer` es, para Laravel, un adaptador detrás del puerto `PlanReader` (el puerto llega en HEXM-0031). Por dentro no es hexagonal, pero separa la lectura de HTTP (ADR 0021): el paquete `importer.reading` no importa nada de FastAPI, y `importer.api` solo traduce HTTP ↔ lectura. Esta tarea mantiene esa frontera y añade un segundo formato a los dos lados:

```
POST /self-weeks/read  ──►  read_self_week()  ──►  SelfWeek  (200)
 (api/self_weeks.py)        (reading/self_week.py)
                                  │
                                  └── Problem[] ──► InvalidWorkbookError ──► 422
                                                    (mismo manejador que la nutricionista)
```

### Las piezas y su papel

- **`reading/models.py`**: `SelfWeek`, `SelfDay`, `SelfDish` e `Ingredient`, modelos de Pydantic con la forma de `self_week`. Validan la forma (días 8-14, 7 días, nombres no vacíos); las reglas del negocio siguen en Laravel.
- **`reading/self_week.py`**: el lector. `detail()` lee «Detalle» fila a fila y anota los problemas. `compare_summary()` compara «Resumen» con lo leído. `read_self_week()` junta las dos, lanza una única excepción si hay problemas y, si no, construye los 7 días.
- **`reading/text.py`**: dos funciones puras nuevas, `self_meal()` («Comida» → `lunch`) e `ingredients()` (el troceado), con las del otro formato. Se prueban con una línea por caso, sin Excel.
- **`reading/workbook.py`** (nuevo): lo que era idéntico en los dos lectores y antes vivía como funciones privadas del de la nutricionista:
  - abrir el `.xlsx`;
  - leer el texto o el número entero de una celda;
  - recorrer las filas con datos;
  - `WorkbookReading`, la base de los dos lectores, que reúne los problemas y comprueba las cabeceras de una hoja.
- **`api/self_weeks.py`**: la ruta, tan fina como la de la nutricionista. El 422 lo responde el mismo manejador registrado en `create_app()`.

Al extraer `workbook.py`, la comprobación de cabeceras pasó a recibir celdas concretas (`{"A2": "Comida"}`) en lugar de una fila, porque «Resumen» lleva etiquetas en una columna. El lector de la nutricionista se adaptó sin cambiar su comportamiento: sus 19 tests siguen pasando igual.

### El recorrido de una petición

1. El usuario sube su Excel a `POST /self-weeks/read` (campo `file`). FastAPI ejecuta la ruta en un hilo aparte, porque es `def` y openpyxl bloquea.
2. `read_self_week()` abre el libro con `open_workbook()`. Si no es un `.xlsx`, ya hay un 422.
3. `detail()` comprueba las cabeceras de «Detalle» y recorre sus filas: día, tipo, que no esté repetida, el 13 sin plato y, si todo encaja, el plato con sus ingredientes troceados. Al final, anota las comidas que faltan.
4. `compare_summary()` comprueba las cabeceras de «Resumen» y compara cada celda con el plato leído de «Detalle». Lo que «Detalle» no pudo leer no se compara, porque su problema ya está anotado.
5. Si hay algún problema, `raise_if_any()` lanza `InvalidWorkbookError` con todos, de las dos hojas, y el manejador responde 422. Si no, sale `SelfWeek` con los 7 días y FastAPI la serializa.

### Los datos de prueba

El Excel real no se versiona (datos de salud). `tests/workbooks.py` construye uno ficticio con la estructura del real, incluidas las etiquetas en mayúsculas de «Resumen» y las filas vacías del 13. Sus platos están pensados para cubrir los casos del troceado: paréntesis con coma, coma decimal, abreviatura, frase final y un plato sin ingredientes. `uv run poe fixtures` lo guarda en `tests/fixtures/semana-2-ficticia.xlsx`, y un test comprueba que el fichero y el constructor no divergen. El Excel real del usuario se probó solo en local: se lee entero y su «Resumen» coincide con «Detalle».

## Alternativas descartadas

- **Ignorar «Resumen».** Era lo más sencillo, pero un resumen desfasado pasaría sin aviso.
- **Nombres de «Resumen» e ingredientes de «Detalle».** Un mismo plato saldría de dos fuentes; si no coinciden, tendría el nombre de una hoja y los ingredientes de otra.
- **Separar la cantidad con expresiones regulares.** Acierta en lo fácil y falla en silencio en lo difícil. Lo hará un LLM en el nivel 9.
- **Una sola ruta que adivine el formato** por las hojas. Quien sube el fichero sabe cuál es, adivinar es otra forma de fallar y mezclaría los dos contratos en OpenAPI. Con una ruta por formato, subir el Excel equivocado da un 422 claro: «falta la hoja «Detalle»».
- **Un lector genérico configurable.** Los dos documentos son demasiado distintos: una fila por menú frente a una por comida, viñetas frente a comas, título con el número frente a nada. Las opciones crecerían más que el código ahorrado, así que se comparte solo lo idéntico.
- **Cómo se haría en un Laravel clásico:** un único `ImportController` con PhpSpreadsheet que lee los dos Excel, valida con `$request->validate()` y guarda el plan con Eloquent en el mismo método. Aquí la lectura vive en otro servicio y no guarda nada: devuelve JSON, y será Laravel, a través de un puerto, quien decida qué hacer con él. Así el dominio no sabe que existe un Excel, y en el nivel 15 se podrá leer el PDF con otro adaptador del mismo puerto.

## Conceptos aplicados

| Concepto | Definición breve | Dónde verlo |
|---|---|---|
| Dos formatos, dos lectores | Cada documento con su lector y su modelo; solo se comparte lo idéntico | `reading/self_week.py`, `reading/nutritionist.py`, `reading/workbook.py` |
| Herencia de `dataclass` | La subclase suma sus atributos a los del padre y hereda sus métodos | `WorkbookReading` y los dos `_Reading` |
| `Literal` y la sentencia `type` | Un tipo cuyos valores son textos concretos, con nombre propio | `SelfMeal` en `reading/text.py` |
| Máquina de estados mínima | Recorrer el texto con un contador de paréntesis, donde una regex no llega | `_split_outside_parentheses` en `reading/text.py` |
| Estrechamiento de tipos (*narrowing*) | `is None` le dice a mypy que, después, el valor no es `None`; `in` no | `detail()` en `reading/self_week.py` |
| Reunir todos los errores | Anotar cada problema y lanzar una única excepción al final | `WorkbookReading.raise_if_any()` |
| Contrato estable ante el futuro | `quantity` se envía ya, a `null`, para que el nivel 9 solo cambie su valor | `Ingredient` en `reading/models.py` |
| Límite del lector determinista | Partir el texto es fiable; interpretar la cantidad, no | `ingredients()` en `reading/text.py`, ADR 0023 |

## Diagramas

- [Leer el Excel de la semana 2](../diagramas/HEXM-0030/leer-la-semana-2-y-sus-errores.workflow.json) (`workflow`) responde a qué comprueba el lector y adónde va cada error: cada fila de «Detalle», el día 13, los platos con sus ingredientes y la comparación con «Resumen». Cada rama de error anota un `Problem`, y todos salen en un único 422.
- [Dos formatos, un servicio](../diagramas/HEXM-0030/dos-formatos-un-servicio.class.json) (`class`) responde a cómo conviven los dos lectores. Cada uno produce su modelo (`NutritionistPlan` o `SelfWeek` → `SelfDay` → `SelfDish` → `Ingredient`), y los dos heredan lo común de `WorkbookReading`.

Cómo verlos: `~/.agents/workspace-kit/bin/diagrams.sh HEXM-0030` (o `cd app-laravel && composer docs:diagrams`) imprime la ruta de cada HTML en `~/.cache/workspace-kit/diagramas/hexagonal/HEXM-0030/`.
