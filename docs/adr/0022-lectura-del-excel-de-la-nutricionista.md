# 0022. Lectura del Excel de la nutricionista: contrato, traducción y errores

**Fecha:** 2026-11-16 · **Estado:** aceptada · **Tarea:** HEXM-0029 · **Relacionado:** [ADR 0014](0014-contrato-de-entrada-de-un-plan-con-el-formato-de-los-documentos.md), [ADR 0021](0021-servicio-importer-herramientas-y-estructura.md)

## Contexto

El servicio `importer` tiene que leer el Excel de la nutricionista y devolverlo como JSON. Ese Excel sale de pasar a mano el PDF y tiene cuatro hojas. Las cabeceras están en la fila 3 y los datos empiezan en la 4:

| Hoja | Contenido |
|---|---|
| «Semana 1» | Título en A1 («Plan nutricional 27 - Semana 1»). Columnas «Menú» (1-6), «Comida» y «Cena», en texto libre. |
| «Desayunos y snacks» | «Momento» (Desayuno, Media mañana, Media tarde), «Menú / indicaciones» (la parte fija, con «… y elegir 2 de las opciones» dentro del texto) y «Opciones», en una sola celda y separadas por `•`. |
| «Intercambios» | «Momento» (Comida, Cena, Comida o cena), «Categoría» y «Equivalencias / pauta». En esta última, los intercambios van separados por rayas: «Tubérculos (1 mediana o 2 pequeñas) – arroz (1/3 vaso) – …». |
| «Receta» | Una receta (ingredientes y preparación). |

El Excel está pensado para leerlo una persona, no un programa, y el dominio de Laravel lo modela de otra forma:

- no tiene «Momento» en los intercambios;
- exige alimento y ración en cada intercambio;
- guarda las reglas aparte, en texto;
- el «elige N» es un número, no una frase.

Había que decidir qué contrato devuelve el servicio, cómo se traduce cada rareza del formato y qué pasa cuando el Excel no tiene la forma esperada.

## Decisión

### 1. El contrato: la parte de la nutricionista del alta de un plan

`POST /nutritionist-plans/read` recibe el `.xlsx` (*multipart*, campo `file`) y devuelve un JSON con la **misma forma que la parte de la semana 1** de `POST /api/nutrition-plans` (ADR 0014): `number`, `fixed_meals`, `exchanges`, `exchange_rules` y `nutritionist_week`. Los valores de `meal_type` y `category` son los de los enums del dominio (`mid_morning`, `carbohydrates`…).

- Faltan `starts_on` (no está en el Excel; la dará el usuario al confirmar la importación) y `self_week` (sale del otro Excel, HEXM-0030).
- El contrato son modelos de Pydantic (`importer/reading/models.py`) con `extra="forbid"` y `frozen=True`. Validan la **forma** (que los números sean positivos y que haya 6 menús), no las reglas del negocio, que siguen siendo del dominio de Laravel (como la *Form Request*, ADR 0007).
- Se llama `read` y no `import` porque no guarda nada.

### 2. Decisiones de traducción

| En el Excel | En el contrato | Por qué |
|---|---|---|
| Título «Plan nutricional 27 - Semana 1» | `number: 27` | El número solo aparece en el título. Se busca «plan nutricional N» sin distinguir mayúsculas. |
| Opciones en una celda separadas por `•` | Una opción por viñeta, sin espacios alrededor | Es el separador que usa la nutricionista. Lo anterior a la primera viñeta también cuenta, por si una celda empieza sin ella. |
| «… y elegir 2 de las opciones» dentro de las indicaciones | `option_groups: [{choose: 2, options: […]}]` y la descripción **sin tocar** | N se reconoce con «elegir / elige / escoger / escoge» seguido de una cifra o una palabra («dos»). La descripción se conserva entera porque es el texto de la nutricionista, y recortarla podría dejarlo cojo. **Si hay opciones y no se encuentra N, es un problema de forma**: adivinar N (¿1?, ¿todas?) sería inventarse el plan. |
| Intercambios separados por raya en una celda | Un `Exchange` por elemento | Se parte por un guion **entre espacios** (`–`, `—` o `-`), así que «6-7 aceitunas» no se rompe. |
| «alimento (ración)» (hidratos, proteínas) | `food: "arroz"`, `serving: "1/3 vaso"` | Es la forma habitual. La ración tiene que empezar por una cantidad, de modo que «queso (+curado)» no se toma por una ración. |
| «ración de alimento» (grasas: «1 puñado de frutos secos», «1/2 aguacate») | `food: "frutos secos"`, `serving: "1 puñado"` | Es la otra forma que usa la nutricionista. Se parte por el primer « de ». Sin «de», la cantidad es la primera palabra: «6-7 aceitunas» da `aceitunas` y `6-7`. |
| Un intercambio sin alimento («> 1/2 plato») | `food: "Vegetales"` (el nombre de la categoría), `serving: "> 1/2 plato"` | El dominio exige alimento, y el más fiel es la propia categoría. Así la categoría no queda vacía y la pauta se conserva. Se reconoce porque empieza por `>`, `<`, «más de» o «menos de». |
| Frase al final de la celda («… (100 g). La legumbre cuenta como 1/2 intercambio de proteína») | Una regla en `exchange_rules` | Una frase que empieza tras un punto, un espacio y una mayúscula es una regla, no un intercambio. «aprox.)» no la corta. |
| Nota en la categoría: «Grasas (solo en una de las dos)», momento «Comida o cena» | La regla «Grasas: solo en una de las dos (comida o cena)» | Es la regla de la grasa (regla 7 del dominio) escrita dentro del nombre. Con su momento se entiende sola. |
| Columna «Momento» de los intercambios | **No se lleva al contrato** | El dominio no la modela: los intercambios son comunes al plan (regla 4). Por eso las filas que se repiten en la comida y en la cena (proteínas y vegetales) se quedan **una sola vez**, y lo mismo las reglas repetidas, conservando el orden. Su única información propia (la grasa, solo en una de las dos) ya viaja como regla. |
| Hoja «Receta» | Se ignora | Llevarla al recetario sería otra tarea. |

Los textos se devuelven **como están escritos**: no se cambian mayúsculas, fracciones ni abreviaturas. Las cabeceras, el momento y la categoría se **reconocen** sin distinguir mayúsculas ni tildes.

### 3. Errores: todos a la vez, con su hoja y su celda

El lector no para en el primer problema. Los va anotando (`Problem(sheet, cell, message)`) y al final lanza una única `InvalidWorkbookError` con todos. La capa HTTP la traduce a **422** en un manejador de excepciones único:

```json
{
  "message": "El Excel no tiene la forma esperada.",
  "errors": [
    {"sheet": "Semana 1", "cell": "C3", "message": "Hoja «Semana 1», celda C3: se esperaba la cabecera «Cena» y hay una celda vacía"},
    {"sheet": "Desayunos y snacks", "cell": null, "message": "Hoja «Desayunos y snacks»: falta la hoja"}
  ]
}
```

- Son problemas de forma una hoja que falta, una cabecera distinta (si falla, no se leen las filas de esa hoja, porque las columnas no son fiables), un título sin número, un menú que falta, sobra o se repite, una comida o cena vacía, un momento o una categoría que no existen, un intercambio que no se reconoce y opciones sin «elegir N».
- Que el plan tenga una pauta de cada tipo, que N no supere las opciones o que la semana sea válida para el calendario **no se comprueba aquí**: lo hace el dominio al dar de alta el plan. La excepción son los menús 1-6. El dominio también diría «la semana debe tener 6 días», pero aquí se puede decir **cuál** falta y en qué hoja.
- Un fichero que no es un `.xlsx` da 422 con `sheet` y `cell` a `null`. Una petición sin fichero recibe el 422 estándar de FastAPI, porque es un error de la petición y no del Excel.

### 4. Estructura

Se sigue el ADR 0021. La lectura vive en `importer/reading/` y no importa nada de FastAPI:

- `text.py`: funciones puras que traducen el texto de una celda;
- `nutritionist.py`: recorre el libro con openpyxl;
- `problems.py`: los problemas y la excepción.

`importer/api/` solo traduce HTTP ↔ lectura. La ruta es `def` y no `async def` porque openpyxl lee de forma bloqueante, y así FastAPI la ejecuta en un hilo aparte.

Dependencias nuevas:

- `openpyxl`, para leer el `.xlsx`;
- `defusedxml`, que openpyxl usa solo si está instalado y protege contra XML malicioso dentro de un fichero subido;
- `python-multipart`, que FastAPI necesita para `UploadFile`;
- `types-openpyxl` (desarrollo), los tipos para mypy.

### 5. Datos de prueba ficticios

Los tests construyen en memoria un Excel ficticio (`tests/workbooks.py`, plan 99) con la misma estructura que el real, y cada caso lo estropea a propósito. El mismo constructor genera `tests/fixtures/plan-nutricionista-ficticio.xlsx` (`uv run poe fixtures`) para probar a mano con `curl`. Un test comprueba que el fichero versionado y el construido se leen igual. El Excel real no se versiona (docs/CONVENCIONES.md, apartado 6; `.gitignore` solo admite `.xlsx` dentro de `tests/fixtures/`).

## Alternativas descartadas

- **Exigir otra forma de Excel** (una columna para N, una fila por intercambio con su ración aparte): sería más fácil de leer, pero obliga al usuario a rehacer el paso manual desde el PDF con un formato artificial. El lector se adapta al documento, no al revés. El adaptador que lea el PDF directamente llegará en el nivel 15.
- **Dejar la descripción sin «elegir N»**: quitar el trozo es frágil (cada plan lo escribe de una forma) y no aporta nada, porque el dato estructurado ya viaja en `choose`.
- **Llevar «Momento» al contrato** (un `moment` por intercambio): el dominio no lo modela, y añadirlo a un contrato que Laravel ignora sería ruido. Si el nivel 8 necesita saber qué intercambios van en la comida y cuáles en la cena, se revisará esta decisión junto con el dominio.
- **Convertir «> 1/2 plato» en una regla** en lugar de en un intercambio: la categoría de vegetales quedaría vacía, y es una equivalencia («medio plato de vegetales»), no una restricción.
- **Parar en el primer error**: obliga a subir el fichero una vez por cada problema. Reunirlos todos es lo que ya hace la *Form Request*.
- **Validar aquí todas las reglas del plan**: duplicaría las del dominio de Laravel, que tiene que aplicarlas igualmente a los planes que no pasan por el importador (el alta manual, el nivel 8).
- **pandas** para leer el Excel: es una dependencia enorme pensada para tablas de datos uniformes, y este Excel es un documento con celdas sueltas (título, notas, viñetas). openpyxl da acceso directo a cada celda.

## Consecuencias

- **A favor:**
  - El servicio devuelve exactamente lo que Laravel ya sabe dar de alta, y el contrato queda descrito en OpenAPI (`NutritionistPlan` e `InvalidWorkbookResponse`) para los tests de contrato de HEXM-0031.
  - Un Excel mal hecho se corrige de una vez, sabiendo la hoja y la celda.
  - La parte difícil (entender el texto) se prueba con funciones puras, una línea por caso.
- **En contra:**
  - El lector depende de cómo escribe la nutricionista: una forma nueva de escribir un intercambio dará un 422 hasta que se enseñe al lector. Es intencionado, porque es mejor un error claro que un dato inventado.
  - Las reglas de los intercambios siguen siendo texto (se harán cumplir en el nivel 8).
- **Revisar cuando:**
  - cambie el formato del Excel;
  - el nivel 8 necesite el momento de cada intercambio;
  - se lea el PDF directamente (nivel 15): será otro adaptador con este mismo contrato.
