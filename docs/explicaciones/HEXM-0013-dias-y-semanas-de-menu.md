# Explicación HEXM-0013: Días y semanas de menú

## Explicación

Un plan nutricional tiene dos semanas de comidas y cenas que se alternan. En HEXM-0012 se crearon las piezas pequeñas (el plato, el tipo de comida, el origen de la semana). Esta tarea las agrupa en las dos estructuras que se repiten en cada plan: el **día de menú** (comida y cena de un día) y la **semana de menú** (seis días con menú y el sábado libre).

El problema tiene dos partes:

1. **Proteger reglas que no miran un objeto suelto, sino un conjunto.** "Seis días", "sin repetir ninguno" y "el sábado no" son reglas sobre la colección de días, no sobre un día. Deben cumplirse siempre, igual que una receta no puede quedarse sin ingredientes.
2. **Dos documentos que numeran los días de forma distinta.** La nutricionista entrega "menú 1 … menú 6" sin decir a qué día corresponde cada uno. El usuario escribe "día 8 … día 14" con el 13 vacío. La aplicación tiene que entender los dos y quedarse con un único modelo.

La idea de la solución es la siguiente:

- un **enum `Weekday`** para hablar de días de la semana;
- un **`MenuDay` value object** que no puede existir sin comida, sin cena o en sábado;
- una **`MenuWeek`** que vigila el conjunto de días;
- dos **constructores con nombre**, uno por formato de documento, que traducen la numeración del documento a días de la semana y luego pasan por las mismas reglas.

## Lógica de negocio

- **Cada semana empieza en lunes y el sábado es libre.** Es el día de dieta menos estricta y de aprovechar sobrantes, así que nunca tiene menú. Quedan seis días con menú: de lunes a viernes y el domingo.
- **Cada día con menú tiene comida y cena, las dos obligatorias.** Un martes con *"Lentejas (½ bote) con pisto"* de comida y nada de cena es un día mal escrito, no un día válido.
- **Ningún día se repite.** Si un documento trae dos lunes, falta otro día, y el mensaje útil es "el lunes aparece más de una vez", no "faltan días".
- **La semana 1 numera los menús del 1 al 6.** Se asignan en orden saltando el sábado: menú 1 → lunes … menú 5 → viernes, y **menú 6 → domingo**. No existe un "menú 7".
- **La semana 2 numera los días del 8 al 14.** El día 8 es el lunes y el 14 el domingo. El **día 13 es el sábado y va vacío**; si alguien lo rellena, es un error: el sábado es libre.
- **La numeración es solo formato.** "Menú 6" y "día 14" significan lo mismo: el domingo. Al negocio le interesa qué se come cada día de la semana, porque esa es la pregunta que responderá el calendario ("¿qué me toca hoy?", nivel 4).
- **Una semana es la 1 o la 2.** Un plan no tiene "semana 3".

## Decisiones de arquitectura

**Todo es dominio puro.** Las piezas nuevas (`Weekday`, `MenuDay`, `MenuWeek`, `InvalidMenuDay`, `InvalidMenuWeek`) viven en `MealPlanning\Domain`. No usan Laravel, ni base de datos, ni HTTP, y se prueban con tests unitarios. Los tests de arquitectura de `composer ci:check` garantizan que siguen sin depender de nada externo. No hay puertos ni adaptadores nuevos: el recorrido de "una petición" es una llamada directa a un constructor del dominio, que devuelve una semana válida o lanza una excepción de negocio.

**Quién vigila cada regla.** Cada invariante vive en el objeto que tiene la información necesaria para comprobarla:

```
 Documento               Traducción (MenuWeek)          Reglas
 ─────────               ─────────────────────          ──────
 menús 1-6  ──► fromNutritionistMenus() ─┐
                 menú N → N-ésimo día     │   MenuDay::of()        MenuWeek::create()
                 con menú (6 → domingo)   ├─► · ¿sábado?        ─► · ¿semana 1 o 2?
 días 8-14  ──► fromSelfDays()           │   · ¿comida?            · ¿día repetido?
                 día N → Weekday N − 7   ─┘   · ¿cena?              · ¿6 días?
                 (13 vacío se salta)                                     │
                                                                         ▼
                                                              MenuWeek (lunes → domingo)
```

- `MenuDay` comprueba lo que se ve en un día suelto: comida, cena y que no sea sábado. Así, un "menú del sábado" no puede existir ni un instante (modelo siempre válido).
- `MenuWeek::create()` comprueba lo que solo se ve en el conjunto: número de semana, días repetidos y cantidad de días. No necesita comprobar "sábado" ni "que sean exactamente de lunes a viernes y domingo": con seis días distintos y ninguno en sábado, por fuerza son esos. Por la misma razón, "más de seis días" siempre aparece como un día repetido.
- Los dos constructores de formato **solo traducen** y llaman a `create()`. Las reglas están en un único sitio, y añadir un tercer formato (por ejemplo, el PDF del nivel 15) no podrá saltárselas.

**La traducción de formatos vive en el dominio, no en el futuro importador.** "El menú 6 es el domingo" no es un detalle del Excel, sino cómo trabaja la nutricionista: si mañana el plan llega en PDF, la regla es la misma. El importador del nivel 7 se limitará a leer el fichero y entregar "menú N → comida y cena" al dominio.

**`MenuDay` acepta un plato vacío (`?Dish`) aunque comida y cena sean obligatorias.** Con un tipo `Dish` a secas, un día sin cena daría un `TypeError` de PHP: un error técnico, en inglés, que no explica nada. Pero estos datos llegarán de documentos externos donde una cena sí puede faltar, y eso es un error de negocio que merece su mensaje: *"Al menú del domingo le falta la cena."* Por dentro, el objeto siempre está completo: `lunch()` y `dinner()` nunca devuelven null.

**Colecciones inmutables, como en `Recipe`.** Los días se guardan en una propiedad `readonly`, `days()` devuelve una copia del array (los arrays de PHP se copian por valor) y cada `MenuDay` es inmutable. Nadie puede quitar o cambiar un día desde fuera. Los días se ordenan siempre de lunes a domingo, lleguen como lleguen.

## Alternativas descartadas

- **Día de la semana como entero ISO "a pelo" (1-7).** Es lo que da PHP con `date('N')` y ahorra una clase, pero cualquier entero pasaría el tipo (0, 9…), habría que validarlo a mano en cada sitio y el código diría `7` en lugar de `Sunday`. Se eligió un **enum respaldado por el entero ISO**: el tipo es cerrado y legible, y el calendario del nivel 4 lo obtendrá de una fecha con `Weekday::from((int) $date->format('N'))`. Se descartó también la numeración `w` de PHP (0 = domingo), porque en el negocio la semana empieza en lunes.
- **`MenuDay` como entidad**, como decía el glosario. Una entidad se justifica cuando hay que seguir a un objeto aunque cambien sus datos: una receta renombrada sigue siendo la misma receta. Un día de menú no tiene ese ciclo de vida: cambiar la cena del martes es sustituir el día entero. Que un día "se identifique por su día de la semana" es una **clave dentro de su semana** (no puede haber dos martes), no una identidad. Por eso es un value object y se corrigió el glosario. `MenuWeek`, en cambio, sí es una **entidad local**: "la semana 2 del plan 27" sigue siendo la misma aunque el usuario le cambie platos en el nivel 8.
- **Cómo se haría en un Laravel clásico.** Un modelo Eloquent `MenuDay` con columnas `weekday`, `lunch` y `dinner`, y las reglas repartidas entre un `FormRequest` ("`lunch` required", "`weekday` in:1,2,3,4,5,7") y el importador. Pero así las reglas solo se aplicarían en la entrada HTTP: un *seeder*, un comando Artisan o el importador podrían guardar un menú en sábado o una semana de cinco días. La traducción "menú 6 → domingo" acabaría en el importador, y cada nuevo formato tendría que reimplementarla. Aquí el dominio es el único que sabe construir una semana, y siempre la construye válida.
- **Validar "sábado" en `MenuWeek` en lugar de en `MenuDay`.** Habría permitido crear un `MenuDay` de sábado suelto que solo fallaría al meterlo en una semana. Se prefirió que no pueda existir.
- **Exigir que la semana 1 sea de la nutricionista y la 2 del usuario.** Hoy siempre es así, pero es una costumbre del documento más que una regla pedida por el negocio. No se inventa: los constructores de formato ya fijan la pareja correcta.
- **Un constructor por formato fuera del dominio** (por ejemplo, un `NutritionistWeekMapper` en infraestructura). Se descartó por el motivo de arriba: la regla de numeración es del negocio.

## Conceptos aplicados

| Concepto | Definición breve | Dónde verlo |
|---|---|---|
| Entidad local frente a value object | Dentro de un agregado, un objeto con identidad solo relativa a su raíz (`MenuWeek`: semana 1 o 2 del plan) frente a uno que se sustituye entero (`MenuDay`). | `MenuWeek.php` y `MenuDay.php` (bloques `CONCEPTO:`) |
| Invariantes sobre colecciones | Reglas de cardinalidad (6 días), unicidad (sin repetidos) y huecos permitidos (solo el sábado) que mira el conjunto, no cada elemento. | `MenuWeek::create()` y `guardAgainstDuplicatedDays()` |
| Modelo siempre válido | Un objeto no puede existir en un estado inválido: se comprueba al construirlo. | `MenuDay::of()` (no hay `MenuDay` de sábado) |
| Traducir formatos a un modelo único | Constructores con nombre que convierten la numeración de cada documento a `Weekday` y comparten las mismas reglas. | `MenuWeek::fromNutritionistMenus()` y `fromSelfDays()` |
| Enum respaldado por un entero | Tipo cerrado cuyo valor es un `int` con significado (ISO-8601), compatible con las fechas de PHP. | `Weekday.php` |
| Regla de negocio con nombre propio | "El sábado es libre" como método (`isFreeDay()`), en un único sitio. | `Weekday::isFreeDay()` |
| Colecciones inmutables | Propiedad `readonly` y arrays copiados por valor: nada cambia la semana desde fuera. | `MenuWeek::days()` y el test `it cannot be changed from outside` |
| Errores de negocio frente a errores técnicos | Aceptar `?Dish` para dar un mensaje en español en lugar de un `TypeError`. | `MenuDay::of()` e `InvalidMenuDay` |

## Diagramas

- [Semana de menú](../diagramas/HEXM-0013/semana-de-menu.class.json) (`class`): ¿qué es entidad y qué es value object, quién compone a quién y qué excepción lanza cada uno?
- [De los documentos a la semana](../diagramas/HEXM-0013/de-los-documentos-a-la-semana.workflow.json) (`workflow`): ¿cómo se convierten los menús 1-6 y los días 8-14 en el mismo `MenuWeek`, y en qué regla se rechaza cada error?

Para verlos: `cd app-laravel && composer docs:diagrams` y abrir `docs/diagramas/HEXM-0013/<slug>.html` en el navegador.
