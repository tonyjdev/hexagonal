# Explicación HEXM-0029: Leer el Excel de la nutricionista

## Explicación

Hasta ahora el servicio `importer` solo respondía `GET /health`. Con esta tarea hace su primer trabajo de verdad: recibe el Excel de la nutricionista (el que sale de pasar a mano el PDF) y devuelve **la parte del plan que contiene**, con la misma forma que la semana 1 del contrato de `POST /api/nutrition-plans` (ADR 0014). Esa parte es `number`, `fixed_meals`, `exchanges`, `exchange_rules` y `nutritionist_week`. Laravel la consumirá a través del puerto `PlanReader` (HEXM-0031).

La dificultad no está en abrir el fichero, sino en **entender lo que escribe una persona para que lo lea otra persona**:

- las opciones de una pauta van en una sola celda, separadas por `•`;
- el «elige 2» va dentro de la frase;
- los intercambios son una lista separada por rayas, escrita de dos formas distintas;
- un intercambio puede no tener alimento («> 1/2 plato»);
- hay reglas escondidas en una frase final o en el nombre de la categoría;
- hay una columna («Momento») que el dominio no modela.

La solución tiene tres piezas:

1. **Funciones puras** (`text.py`) que traducen el texto de una celda, una decisión por función, probadas con una línea por caso.
2. **Un lector** (`nutritionist.py`) que recorre las hojas con openpyxl, comprueba cabeceras y menús y **anota todos los problemas** con su hoja y su celda, en lugar de parar en el primero.
3. **Un adaptador HTTP fino** (`POST /nutritionist-plans/read`) que recibe el fichero con `UploadFile`, llama al lector y deja que un manejador único convierta los problemas en un 422.

Las decisiones de traducción y sus alternativas están en el [ADR 0022](../adr/0022-lectura-del-excel-de-la-nutricionista.md).

## Lógica de negocio

El importador **no decide nada del negocio**: traduce un formato a otro y deja las reglas al dominio de Laravel. Pero traducir obliga a interpretar, y cada interpretación es una decisión que se ve con ejemplos reales del formato.

**Del título sale el número de plan.** De «Plan nutricional 27 - Semana 1» sale `number: 27`. Si el título no lo lleva, el lector responde «Hoja «Semana 1», celda A1: no se encuentra el número de plan».

**Una pauta fija con opciones**:

| Momento | Menú / indicaciones | Opciones |
|---|---|---|
| Media mañana | Tostada integral con aguacate … y elegir 2 de las opciones | • 2 lonchas de pavo<br>• 1 lata de pescado …<br>(7 opciones) |

se convierte en `{meal_type: "mid_morning", description: "Tostada … y elegir 2 de las opciones", option_groups: [{choose: 2, options: [7 opciones]}]}`. La descripción se conserva **entera**, porque es el texto de la nutricionista. Si hubiera opciones y no se dijera cuántas elegir, el lector **no adivina**: responde 422 en la celda B de esa fila. Elegir 1 o todas sería inventarse el plan.

**Los intercambios.** En la celda de proteínas pone «Huevo (2 uds y 2 claras) – carne (4 filetes) – … – lácteos (100 g fresco aprox.). La legumbre cuenta como 1/2 intercambio de proteína». De ahí salen:

- un intercambio por elemento, con la forma «alimento (ración)»: `carne` / `4 filetes`;
- la frase final, como regla: «La legumbre cuenta como 1/2 intercambio de proteína».

Las grasas se escriben al revés, con la forma «ración de alimento»: de «1 puñado de frutos secos» sale `frutos secos` / `1 puñado`, y de «6-7 aceitunas» sale `aceitunas` / `6-7`. El guion de «6-7» no parte el intercambio porque solo se parte por guiones **entre espacios**.

**Un intercambio sin alimento.** «> 1/2 plato» en la fila de vegetales se convierte en `food: "Vegetales"` y `serving: "> 1/2 plato"`. El dominio exige alimento (regla 17), y el más fiel es la propia categoría, que así no queda vacía.

**La regla de la grasa**, escondida en «Grasas (solo en una de las dos)» con el momento «Comida o cena», se convierte en la regla «Grasas: solo en una de las dos (comida o cena)». Es la regla 7 del dominio, que se hará cumplir en el nivel 8.

**El momento no se modela.** Los intercambios son comunes al plan (regla 4). Por eso las proteínas y los vegetales, que el Excel repite para la comida y para la cena, se quedan **una vez**, y lo mismo las reglas repetidas.

**Qué es un error de forma y qué no.** El importador comprueba lo que solo él puede situar en una celda:

- hojas y cabeceras;
- el título;
- que estén los menús del 1 al 6, sin repetir;
- comida y cena no vacías;
- momentos y categorías conocidos;
- intercambios reconocibles;
- «elegir N» cuando hay opciones.

Que N no supere las opciones, que no haya dos desayunos o que el plan empiece en lunes lo sigue diciendo el dominio, venga el plan de donde venga. Los menús 1-6 son la excepción consciente: el dominio también diría «la semana debe tener 6 días», pero aquí se puede decir «falta el menú 4».

## Decisiones de arquitectura

### Dónde encaja

```
 Excel (.xlsx) ──multipart──► importer (FastAPI)                     Laravel (HEXM-0031)
                              ┌─────────────────────────────────┐
                              │ api/  nutritionist_plans.py      │──► JSON del contrato ──► PlanReader
                              │       errors.py (422)            │      (o 422)                │
                              │           │ llama                │                       RegisterNutritionPlan
                              │           ▼                      │                        (reglas del dominio)
                              │ reading/ nutritionist.py         │
                              │          text.py · models.py     │
                              │          problems.py             │
                              └─────────────────────────────────┘
```

Para el núcleo hexagonal, todo el servicio es **un adaptador** detrás del puerto `PlanReader`. Dentro del servicio no hay hexagonal completa (ADR 0021), pero sí la regla que importa: **la lectura no sabe nada de HTTP**. `importer/reading/` no importa FastAPI. Recibe un `BinaryIO` y devuelve un modelo o lanza `InvalidWorkbookError`. `importer/api/` solo traduce HTTP ↔ lectura. Las dependencias van en un solo sentido: `api` → `reading`, nunca al revés.

### Las piezas y su papel

| Pieza | Papel | Equivalente en Laravel |
|---|---|---|
| `reading/models.py` (`NutritionistPlan` y compañía) | El contrato de salida: forma y reglas de forma en la misma clase. `extra="forbid"` hace que una errata en un campo falle, y `frozen=True` los vuelve inmutables. | DTO + *Form Request* + *API Resource* |
| `reading/text.py` | Traduce el texto de una celda: funciones puras, una decisión por función. | Los constructores con nombre del dominio (`Quantity::fromString()`) |
| `reading/nutritionist.py` (`_Reading`) | Recorre las hojas, comprueba cabeceras y menús y anota los problemas. | La parte de `toCommand()` que lee la petición |
| `reading/problems.py` | `Problem(sheet, cell, message)` y la excepción que los lleva todos. | Los errores de validación con la ruta de cada campo |
| `api/nutritionist_plans.py` | La ruta: `UploadFile` → lector → modelo. | Un controlador invocable |
| `api/errors.py` | `InvalidWorkbookError` → 422, en un único sitio. | `->render()` de `bootstrap/app.php` (ADR 0007) |

### El recorrido de una petición

1. `curl -F file=@plan.xlsx …/nutritionist-plans/read`. FastAPI lee el formulario *multipart* (con `python-multipart`) y entrega un `UploadFile`.
2. La ruta es `def`, no `async def`: openpyxl lee de forma bloqueante, y así FastAPI la ejecuta en un hilo aparte sin parar el servidor. Llama a `read_nutritionist_plan(file.file)`.
3. `_open()` carga el libro (`data_only=True`, con `defusedxml` protegiendo el XML). Si no es un `.xlsx`, lanza `InvalidWorkbookError` con un único problema sin hoja.
4. `_Reading` lee el título, la semana, las pautas y los intercambios. Cada parte comprueba sus cabeceras (si fallan, no lee las filas de esa hoja), pasa el texto de cada celda a `text.py` y anota los `Problem`.
5. Si hay problemas, lanza `InvalidWorkbookError(problems)`. La ruta no tiene `try`: FastAPI busca el manejador registrado en `create_app()` y responde `422 {message, errors: [{sheet, cell, message}]}`.
6. Si no los hay, construye `NutritionistPlan`. Pydantic lo valida y FastAPI lo serializa a JSON (200), usando el tipo de retorno de la ruta como modelo de la respuesta y del contrato OpenAPI.

### Los datos de prueba

El Excel real no se versiona: tiene datos de salud (CONVENCIONES, apartado 6). `tests/workbooks.py` construye en memoria un Excel **ficticio** (plan 99) con la misma estructura: hojas, títulos combinados, cabeceras en la fila 3, viñetas y rayas. Cada test lo estropea a propósito: borra una hoja, cambia una cabecera, quita un menú. El mismo constructor guarda `tests/fixtures/plan-nutricionista-ficticio.xlsx` (`uv run poe fixtures`) para probar a mano, y un test comprueba que el fichero versionado y el construido se leen igual.

## Alternativas descartadas

- **Cómo se haría en un Laravel clásico**: un controlador con PhpSpreadsheet que lee el Excel y crea los modelos Eloquent en el mismo método. La lectura quedaría mezclada con HTTP y con la persistencia, y no se podría probar sin una petición. Aquí la lectura es una función que recibe un fichero y devuelve datos, y el alta sigue siendo del caso de uso de Laravel, con sus reglas.
- **Exigir un Excel «más fácil»** (una columna para N, una fila por intercambio): obligaría al usuario a rehacer el paso desde el PDF con un formato artificial. El lector se adapta al documento. El PDF directo llegará en el nivel 15, como otro adaptador del mismo contrato.
- **Parar en el primer error**: habría que subir el fichero una vez por problema. Se reúnen todos, como hace la *Form Request*.
- **Validar aquí todas las reglas del plan**: duplicaría el dominio, que tiene que aplicarlas igualmente al alta manual y, en el nivel 8, a la semana editada desde la app.
- **Llevar «Momento» al contrato**: Laravel lo ignoraría. Si el nivel 8 lo necesita, se revisará junto con el dominio.
- **Convertir «> 1/2 plato» en una regla**: la categoría de vegetales quedaría vacía, y es una equivalencia, no una restricción.
- **pandas**: es para tablas uniformes y este Excel es un documento con celdas sueltas. openpyxl da acceso directo a cada celda y pesa mucho menos.
- **Excepciones sin sufijo** (`InvalidWorkbook`, como `InvalidQuantity` en PHP): en Python la convención es `…Error` (PEP 8, regla N818 de ruff). Cada lenguaje, su estilo.

## Conceptos aplicados

- **Modelo de Pydantic como contrato**: forma, validación y serialización en una clase; `Field(ge=1)`, `extra="forbid"` y `frozen=True`. Ver `services/importer/src/importer/reading/models.py`.
- **`StrEnum`**: un enum cuyos casos son textos, como un *backed enum* de PHP. Ver `MealType` y `ExchangeCategory` en `models.py`.
- **Funciones puras y expresiones regulares** (`re`, grupos con nombre, operador morsa `:=`). Ver `services/importer/src/importer/reading/text.py`.
- **Normalización Unicode** para comparar sin tildes (`unicodedata`, NFKD). Ver `normalize()` en `text.py`.
- **openpyxl**: libro, hojas, celdas por coordenada y `data_only`. Ver `services/importer/src/importer/reading/nutritionist.py`.
- **Reunir errores en lugar de lanzar el primero** (`dataclass` `Problem` y una excepción con la lista). Ver `services/importer/src/importer/reading/problems.py`.
- **`field(default_factory=list)`, generadores (`yield`) y `match` con tipos**. Ver `_Reading`, `_data_rows()` y `_menu_number()` en `nutritionist.py`.
- **`UploadFile` y *multipart***, y **`def` frente a `async def`** en una ruta. Ver `services/importer/src/importer/api/nutritionist_plans.py`.
- **Manejador de excepciones de FastAPI**: un único sitio para el 422. Ver `services/importer/src/importer/api/errors.py` y `create_app()` en `main.py`.
- ***Test data builder*, *fixtures* de pytest y `parametrize`**. Ver `services/importer/tests/workbooks.py`, `test_nutritionist_plans_api.py` y `test_text.py`.

## Diagramas

- [Del Excel al contrato](../diagramas/HEXM-0029/del-excel-al-contrato.dataflow.json) (`dataflow`) responde a cómo cambian de forma los datos. El Excel entra por la ruta y cada hoja se lee por separado. El texto pasa por las funciones puras de `text.py` y acaba en `NutritionistPlan` (200) o en `Problem[]` y un 422.
- [POST /nutritionist-plans/read de punta a punta](../diagramas/HEXM-0029/leer-el-excel-de-punta-a-punta.sequence.json) (`sequence`) responde a qué pasa con una petición, en sus dos caminos:
  - Excel válido: ruta → lector → texto → modelo → 200;
  - Excel mal formado: los problemas se anotan, se lanza `InvalidWorkbookError` sin `try` y el manejador responde 422.

Cómo verlos: `~/.agents/workspace-kit/bin/diagrams.sh HEXM-0029` (o `cd app-laravel && composer docs:diagrams`) imprime la ruta de cada HTML en `~/.cache/workspace-kit/diagramas/hexagonal/HEXM-0029/`.
