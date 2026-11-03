# 0013. Persistencia del agregado NutritionPlan como documento

**Fecha:** 2026-11-04 · **Estado:** aceptada · **Tarea:** HEXM-0020 · **Relacionado:** [ADR 0006](0006-persistencia-del-agregado-recipe.md), [ADR 0010](0010-limites-del-agregado-nutritionplan-y-vigencia.md), [ADR 0011](0011-reglas-entre-agregados-en-el-contrato-del-repositorio.md)

## Contexto

El puerto `NutritionPlanRepository` solo tenía un adaptador en memoria (HEXM-0018). Para guardar los planes en PostgreSQL había que decidir cómo repartir el agregado en la base de datos. El agregado es grande:

- un plan con sus pautas fijas, cada una con sus grupos «elige N» y sus opciones;
- una tabla de intercambios por categoría, con reglas en texto;
- dos semanas de seis días, cada día con su comida y su cena;
- y cada plato, en uno de sus dos formatos: texto libre, o nombre con ingredientes.

Con el criterio del recetario (ADR 0006, una tabla por colección) saldrían unas nueve tablas. Pero hay tres hechos que cambian el planteamiento:

1. **El agregado siempre se lee y se escribe entero.** Ninguna consulta pide «el miércoles de la semana 2» por separado: `GetMenuForDate` carga el plan y se lo pasa a `MealCalendar`.
2. **Las dos reglas del repositorio solo miran `starts_on`**: la vigencia y «un inicio por día» (ADR 0011).
3. **Las partes no tienen identidad propia.** Días, platos, opciones e intercambios son *value objects*; las semanas son entidades locales, identificadas solo por su número dentro del plan.

Opciones barajadas:

- **(a)** todo normalizado, unas nueve tablas;
- **(b)** un híbrido: el plan con documentos y una tabla `menu_days` con la comida y la cena en JSON;
- **(c)** una sola tabla con el contenido en `jsonb`.

## Decisión

1. **Opción (c): una fila por plan en `nutrition_plans`.**
   - **Columnas normales para lo que se consulta o se indexa:**
     - `id` (uuid, generado por el dominio);
     - `number`;
     - `starts_on` (tipo `date`: un día del calendario, sin hora ni zona horaria), con **índice único**.
   - **Tres documentos `jsonb`**: `fixed_meals` (lista), `exchange_table` (objeto con `exchanges` y `rules`) y `weeks` (lista de dos semanas con sus días y platos).
     - Los platos se guardan siempre con la misma forma: `name`, `description` e `ingredients`, con `null` en el campo que no aplica.
     - El día de la semana se guarda como su número ISO.
     - La tabla de intercambios se guarda como una lista plana, en el orden de las categorías del enum.
   - **`jsonb` y no `json`**: se guarda ya analizado, se puede indexar y admite operadores (`jsonb_set`, `jsonb_typeof`…).
2. **Un `CHECK` mínimo sobre los documentos**: cada uno debe ser del tipo JSON esperado y `weeks` debe tener exactamente dos elementos. Es una red para los cambios a mano; la forma completa la valida el dominio al cargar.
3. **`NutritionPlanMapper` trata el JSON guardado como una entrada no confiable.**
   - Cada valor se comprueba antes de pasarlo al dominio.
   - Un dato corrupto lanza `UnexpectedValueException` con su ruta exacta (por ejemplo, «weeks[1].days[2].lunch.name debería ser un texto»).
   - El plan se reconstruye con los constructores del dominio (`NutritionPlan::create()`, `MenuWeek::create()`, `MenuDay::of()`…). Así, un documento que rompe una regla, como un día repetido, falla al cargar con la misma excepción que al crearlo (ADR 0006).
4. **«Dos planes no empiezan el mismo día» la garantiza el índice único.**
   - El repositorio captura `UniqueConstraintViolationException`, busca el plan que ya ocupa ese día y lanza `DuplicatedPlanStart` con el mismo mensaje que el adaptador en memoria.
   - Si no encuentra ninguno, la violación no era la esperada y se relanza tal cual.
5. **`save()` es un `updateOrCreate()` dentro de `transaction()`, y la transacción no está para la atomicidad.** Una sola fila es una sola sentencia, que PostgreSQL ya hace atómica. Está porque en PostgreSQL un error **aborta** la transacción que lo contiene.
   - Dentro de otra transacción (la de `RefreshDatabase` o la de un caso de uso), la transacción anidada es un `SAVEPOINT`. Al fallar se deshace hasta él, y se puede consultar el plan que ocupa ese día.
   - Se comprobó quitándola:
     - un plan nuevo en un día ocupado seguía funcionando, porque `createOrFirst()` de Laravel ya envuelve el `INSERT` en un *savepoint*;
     - un plan guardado que se movía a un día ocupado (el `UPDATE`) fallaba con `SQLSTATE[25P02] current transaction is aborted`.

     Ese caso se añadió a los tests de contrato.
6. **Los tests de contrato pasan a `tests/Feature`** con el *dataset* `eloquent`. Se añadieron dos tests:
   - uno que compara el plan recuperado con el original propiedad a propiedad (`toEqual`), porque `equals()` de un agregado solo compara el id;
   - el del `UPDATE` duplicado.

## Consecuencias

- **A favor:**
  - El mapeo es directo: un documento por parte del agregado y una sola consulta para cargar el plan. No hay tablas hijas que borrar y reinsertar, ni filas huérfanas posibles.
  - La forma de la fila refleja la del agregado: lo que el dominio trata como una unidad, la base de datos también.
  - El dominio, la aplicación y el puerto no han cambiado (`git diff` vacío en `MealPlanning\Domain` y `MealPlanning\Application`).
  - La regla 5 la cumple PostgreSQL también con dos guardados simultáneos.
- **En contra:**
  - PostgreSQL no valida el contenido del JSON más allá del `CHECK`. La integridad de los documentos depende del *mapper* y del dominio, que la comprueban al cargar. Un dato corrupto no circula por la aplicación, pero solo se detecta cuando alguien lee ese plan.
  - Los documentos no tienen versión. Si cambia su forma (por ejemplo, un `RecipeId` en los platos, en el nivel 6), habrá que migrar los datos guardados o hacer que el *mapper* lea las dos formas.
  - Consultar dentro de los documentos (por ejemplo, «qué platos usan la receta X») requiere operadores `jsonb` en SQL, menos familiares que un `JOIN`.
- **Revisar cuando:**
  - haga falta consultar partes del plan sin cargarlo entero, como un listado de platos para las búsquedas del nivel 10 o la reacción a `RecipeDeleted` del nivel 6. Entonces quizá convenga un índice GIN sobre `weeks` o un modelo de lectura propio (CQRS);
  - cambie la forma de un documento: entonces habrá que decidir entre una migración de datos y añadir un campo `version`.
