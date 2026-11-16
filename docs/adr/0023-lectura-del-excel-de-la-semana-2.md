# 0023. Lectura del Excel de la semana 2: dos hojas, ingredientes en texto y un segundo formato

**Fecha:** 2026-11-16 · **Estado:** aceptada · **Tarea:** HEXM-0030 · **Relacionado:** [ADR 0014](0014-contrato-de-entrada-de-un-plan-con-el-formato-de-los-documentos.md), [ADR 0021](0021-servicio-importer-herramientas-y-estructura.md), [ADR 0022](0022-lectura-del-excel-de-la-nutricionista.md)

## Contexto

La semana 2 de un plan la elabora el usuario en su propio Excel, con un formato distinto al de la nutricionista. Tiene dos hojas, las dos escritas a mano (no hay fórmulas):

| Hoja | Contenido |
|---|---|
| «Detalle» | Cabeceras en la fila 1: «Día» (8-14), «Tipo» (Comida o Cena), «Plato» (nombre corto) e «Ingredientes / cantidades», separados por comas: «⅓ vaso de arroz en crudo, 2 filetes de merluza, tomate, rúcula.». Las dos filas del día 13 (el sábado libre) van sin plato. |
| «Resumen» | Una cuadrícula: los días en la fila 1 («DÍA 8» … «DÍA 14», columnas B a H) y «COMIDA» y «CENA» en A2 y A3, con solo el nombre de cada plato. |

El contrato de destino es `self_week` de `POST /api/nutrition-plans` (ADR 0014): una lista de `{day, lunch: {name, ingredients: [{name, quantity}]}, dinner}`, con el 13 vacío. Quedaban tres preguntas abiertas:

- qué papel tiene «Resumen», que repite los nombres de «Detalle»;
- qué se hace con los ingredientes, que vienen en texto libre;
- cómo convive este segundo formato con el lector de la nutricionista.

## Decisión

### 1. El contrato: la parte `self_week` del alta de un plan

`POST /self-weeks/read` recibe el `.xlsx` (*multipart*, campo `file`) y devuelve `{"self_week": [...]}`, siempre con los 7 días del 8 al 14 y en orden. El 13 sale como `{"day": 13, "lunch": null, "dinner": null}`. `recipe_id` no se envía: el Excel no lo tiene.

### 2. «Detalle» es la fuente y «Resumen» se comprueba

Los platos se leen de «Detalle», que es la única hoja con los ingredientes. «Resumen» es una **segunda copia, escrita a mano**, de los nombres, y puede quedarse desfasada si se corrige un plato en una hoja y no en la otra. Si no coinciden, el lector no puede saber cuál es la buena, y quedarse con una en silencio escondería el error. Por eso:

- se comparan los nombres de cada día y comida sin distinguir mayúsculas, tildes ni espacios de más («LENTEJAS  ESTOFADAS» y «Lentejas estofadas» son el mismo plato);
- si no coinciden, o falta uno, responde **422** señalando la celda de «Resumen» y citando la de «Detalle»: «Hoja «Resumen», celda C3: el plato «Crema de calabaza» no coincide con el de la hoja «Detalle» (celda C5: «Crema de verduras con queso»)»;
- el día 13 tampoco puede tener plato en «Resumen»;
- las dos hojas son obligatorias.

### 3. Ingredientes: cada trozo con su texto entero, sin separar la cantidad

Cada ingrediente sale como `{"name": "⅓ vaso de arroz en crudo", "quantity": null}`: el texto entero en `name` y `quantity` siempre a `null`. La celda se parte así:

- por **una coma seguida de espacio** (o al final del texto), salvo dentro de paréntesis: «Pasta (¾ vaso en crudo, integral)» es un ingrediente. Pedir el espacio deja entera la coma decimal («1,5 kg de patatas»);
- por **un punto que acaba una frase** (seguido de espacio y mayúscula): «…2 huevos. Chocolate 85%.» da «2 huevos» y «Chocolate 85%». El punto de una abreviatura va seguido de minúscula («1 cda. de queso») y no parte nada;
- se quita el punto final de la celda y se descartan los trozos vacíos.

No se separa la cantidad del ingrediente porque un lector determinista no puede hacerlo con fiabilidad: «tomate» no tiene cantidad, «1 lata y media de atún» la tiene repartida, «6-8 tomates cherry» es un intervalo y «Media tarrina de queso fresco de cabra (125 g)» lleva dos. Es trabajo de un LLM con *structured outputs* (nivel 9, puerto `IngredientExtractor`). El campo `quantity` se envía igualmente para que la forma del contrato no cambie cuando llegue ese nivel; solo cambiará su valor. El dominio ya lo admite: la cantidad de un `DishIngredient` es opcional.

### 4. Forma de la semana comprobada en el lector

El lector comprueba, para poder decir hoja y celda:

- días del 8 al 14, cada uno con su comida y su cena y sin repetir ninguna («la comida del día 8 está repetida»);
- tipo «Comida» o «Cena» («el tipo «Merienda» no es «Comida» ni «Cena»»);
- todos los días con plato salvo el 13, y el 13 sin plato ni ingredientes («el día 13 es el sábado libre: no puede tener plato»). Las filas del 13 pueden estar vacías o no aparecer;
- un plato sin ingredientes es válido (regla 13 del dominio): sale con `ingredients: []`.

Las reglas de la semana siguen en el dominio de Laravel (`MenuWeek`, `MenuDay`), que las vuelve a comprobar venga la semana de donde venga. Los errores reúnen todos los problemas de las dos hojas y llegan con el mismo formato que los del Excel de la nutricionista (ADR 0022).

### 5. Dos formatos, dos lectores, y lo común aparte

`importer/reading/self_week.py` es un lector nuevo con su modelo (`SelfWeek`). No se conoce con el de la nutricionista (`nutritionist.py`). Lo que es idéntico en los dos pasa a `importer/reading/workbook.py`:

- abrir un `.xlsx` subido;
- leer el texto o el número entero de una celda;
- recorrer las filas con datos;
- comprobar las cabeceras de una hoja, ahora por celda (`{"A2": "Comida"}`), porque «Resumen» lleva etiquetas en una columna;
- reunir los problemas (`WorkbookReading`, la base de los dos lectores).

Las funciones puras de texto nuevas (`self_meal` e `ingredients`) van a `text.py`, con las del otro formato. Hay una ruta por formato (`/nutritionist-plans/read` y `/self-weeks/read`) y un único manejador del 422.

## Alternativas descartadas

- **Ignorar «Resumen».** Era lo más sencillo, pero una hoja desfasada pasaría sin aviso y el usuario creería que lo importado es lo que ve en el resumen.
- **Leer los platos de «Resumen» y los ingredientes de «Detalle».** Mezcla dos fuentes para un mismo plato; si no coinciden, el plato tendría el nombre de una hoja y los ingredientes de otra.
- **Separar la cantidad con expresiones regulares** («número + unidad + de + ingrediente»). Acierta en los casos fáciles y falla en silencio en los demás («1 lata y media de atún» → cantidad «1»). Un dato equivocado con aspecto de correcto es peor que un texto entero; el nivel 9 lo hará con un LLM.
- **Una sola ruta que adivine el formato** por las hojas del Excel. Quien sube el fichero ya sabe cuál es; adivinar sería otra forma de fallar y mezclaría los dos contratos en OpenAPI. Con una ruta por formato, subir el Excel equivocado da un 422 claro («falta la hoja «Detalle»»).
- **Un lector genérico configurable** para los dos formatos. Con dos documentos tan distintos (una fila por menú frente a una por comida, viñetas frente a comas), las opciones crecerían más que el código que ahorran. Se comparte solo lo idéntico.

## Consecuencias

- El servicio lee los dos documentos de un plan. Laravel los consumirá a través del puerto `PlanReader` y los juntará en una importación pendiente (HEXM-0031 y siguientes).
- Los ingredientes de la semana 2 llegan sin cantidad separada. Si la lista de la compra (nivel 9) necesita sumarlas, `IngredientExtractor` tendrá que separarlas antes, como con los platos de la semana 1.
- Si el usuario cambia la estructura de su Excel (otra hoja, otras columnas), el lector responde 422 con la celda de la cabecera que no encaja, y hay que adaptarlo.
- Los tests usan un Excel ficticio construido en memoria (`tests/workbooks.py`), que `uv run poe fixtures` guarda en `tests/fixtures/semana-2-ficticia.xlsx` para probar a mano. El Excel real no se versiona.
