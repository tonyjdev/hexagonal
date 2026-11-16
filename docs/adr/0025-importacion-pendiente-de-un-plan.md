# 0025. Importación pendiente de un plan

**Fecha:** 2026-11-16 · **Estado:** aceptada · **Tarea:** HEXM-0032 · **Relacionado:** [ADR 0010](0010-limites-del-agregado-nutritionplan-y-vigencia.md), [ADR 0013](0013-persistencia-del-agregado-nutritionplan-como-documento.md), [ADR 0014](0014-contrato-de-entrada-de-un-plan-con-el-formato-de-los-documentos.md), [ADR 0016](0016-referencia-entre-contextos-y-evolucion-del-documento.md), [ADR 0024](0024-puerto-planreader-y-contrato-con-el-importador.md)

## Contexto

Un plan nutricional llega en **dos documentos y en dos momentos**:

1. la nutricionista entrega su Excel: número, pautas fijas, intercambios y semana 1;
2. días después, el usuario elabora el Excel de la semana 2 (días 8-14).

El puerto `PlanReader` (ADR 0024) ya lee cada uno por separado. Pero un `NutritionPlan` exige exactamente las dos semanas (regla 1, ADR 0010), y la fecha de inicio no está en ningún Excel. Entre el primer documento y la confirmación, lo leído tiene que vivir en algún sitio.

Al planificar el nivel 7 se eligió la **opción 3**: una importación pendiente que empieza con el Excel de la nutricionista, recibe después el de la semana 2 y, con los dos y la fecha de inicio, se confirma como un `NutritionPlan`. Faltaba decidir sus reglas, su modelo y cómo se guarda.

## Decisión

### 1. Un agregado propio, `PlanImport`, y `NutritionPlan` no cambia

Se descarta relajar `NutritionPlan` para admitir «solo la semana 1». Eso rompería su invariante principal y obligaría a todo lo que usa un plan (el calendario, el histórico, la API) a comprobar si está completo.

La importación es otro agregado de `MealPlanning`, con su identificador (`PlanImportId`, UUID v7) y su repositorio (`PlanImportRepository`). Lo incompleto tiene nombre propio, y el plan sigue siendo siempre válido. En `DOMINIO.md`, `PlanImport` deja de ser un caso de uso y pasa a ser este agregado.

### 2. Estados y reglas (decisiones de negocio)

```
AwaitingSelfWeek ──(semana 2)──► ReadyToConfirm ──(fecha de inicio)──► Confirmed
```

| Regla | Decisión |
|---|---|
| ¿Con qué empieza? | Siempre con el Excel de la nutricionista (`PlanImport::start()`). |
| ¿Se pueden reemplazar los Excel? | **Sí, los dos**, mientras no esté confirmada: `replaceNutritionistPlan()` (conserva la semana 2) y `attachSelfWeek()`, que añade o sustituye. Así se corrige un error del Excel subiéndolo otra vez. |
| ¿Se puede confirmar sin semana 2? | **No**: `InvalidPlanImport` (422), y no se crea ningún plan. |
| ¿Qué pasa al confirmarla? | **Se queda confirmada**, con el id del plan creado, como registro de dónde salió el plan. Ya no se puede cambiar ni volver a confirmar (`InvalidPlanImport`). |
| ¿Varias pendientes a la vez? | **Sí, sin límite.** No hay regla entre importaciones. Las reglas entre planes (número e inicio únicos) se comprueban al confirmar. |

- **El estado se deduce, no se guarda en el agregado.** `status()` lo calcula de lo que tiene: si tiene plan, está confirmada; si tiene semana 2, está lista; si no, espera la semana 2. Como la vigencia de un plan, un dato derivado no se guarda dos veces.
- **El agregado es inmutable**, como `NutritionPlan` (ADR 0019): cada transición devuelve una versión nueva con el mismo id.

### 3. El contenido se guarda como datos, no como objetos del dominio

`ImportedNutritionistPlan` e `ImportedSelfWeek` son value objects del dominio con el contenido de cada Excel **en la forma del contrato del alta** (ADR 0014), sin convertirlo en `FixedMeal`, `ExchangeTable` o `MenuWeek`. Solo el número se interpreta (`PlanNumber`), porque identifica la importación para el usuario.

- **Por qué no se reutilizan las lecturas del puerto:** `NutritionistPlanReading` y `SelfWeekReading` viven en `Application`, y el dominio no puede depender de ellas. `Application\ImportedDocuments` traduce de unas a otros, copiando.
- **Por qué no se construyen ya los `MenuWeek`:** al confirmar, el contenido se copia tal cual a `RegisterNutritionPlanCommand` y el plan se construye con **el mismo código que el alta manual**. Mismas reglas, mismos mensajes y, con los mismos datos, el mismo plan (lo comprueba un test que compara los dos caminos). Construirlos aquí supondría dos traducciones del documento al dominio que mantener iguales.
- **El precio:** un menú que falte no se detecta al subir el Excel, sino al confirmar. Es aceptable porque el servicio ya comprueba la forma del documento al leerlo (`UnreadableDocument`), y las reglas del plan solo tienen sentido con el plan entero.

### 4. Casos de uso: confirmar reutiliza el alta

Los cuatro casos de uso viven en `MealPlanning\Application` y reciben el id de la importación como texto:

- `StartPlanImport(PlanDocument)` lee con `PlanReader::readNutritionistPlan()`, crea la importación y devuelve su `PlanImportId`.
- `ReplaceImportedNutritionistPlan(id, PlanDocument)` y `AttachSelfWeekToPlanImport(id, PlanDocument)` siguen el mismo recorrido: buscan la importación (`PlanImportNotFound`, 404), leen el documento, aplican la transición y guardan. **Buscan antes de leer**: si la importación no existe, no se gasta la llamada al servicio.
- `ConfirmPlanImport(id, fecha de inicio)` hace tres pasos:
  1. pregunta al agregado si se puede confirmar (`selfWeekForConfirmation()`);
  2. compone un `RegisterNutritionPlanCommand` y llama a **`RegisterNutritionPlan`**, inyectado por el constructor;
  3. anota el id del plan en la importación (`confirm()`) y la guarda.

  Las reglas del plan (lunes, dos semanas, número e inicio únicos, recetas) se heredan sin reescribirlas.

### 5. Dos agregados, dos guardados, sin transacción común

Confirmar crea un `NutritionPlan` y cambia una `PlanImport`. Se sigue la regla práctica de DDD de «un agregado por transacción»:

- cada uno se guarda en su repositorio, el plan primero (sin plan no hay id que anotar);
- si algo falla **antes** de guardar el plan (la importación no se puede confirmar, la fecha no es lunes, el número ya existe…), no se guarda nada;
- si fallara **el segundo guardado**, el plan existiría y la importación seguiría pendiente. Confirmarla otra vez daría `DuplicatedPlanNumber` (regla 22), así que nunca habría dos planes iguales.

Para una aplicación de un solo usuario, con una fila por agregado, este riesgo se acepta antes que añadir un puerto de transacciones al núcleo. Si algún día molesta, una salida es que la confirmación reconozca ese caso y enlace el plan ya creado.

### 6. Persistencia: una fila por importación con documentos `jsonb`

Como los planes (ADR 0013), la importación se lee y se escribe siempre entera. La tabla `plan_imports` tiene:

- `id` (uuid);
- `status`;
- `nutritionist_plan` (`jsonb`, un objeto);
- `self_week` (`jsonb`, una lista, o NULL);
- `nutrition_plan_id` (uuid, o NULL).

Además:

- **`status` se guarda como columna**, aunque el dominio lo deduce: así la tabla se lee a simple vista, y un `CHECK` impide filas incoherentes (una «confirmada» sin plan o sin semana 2). El *mapper* lo escribe a partir del dominio y no lo usa al leer.
- **Sin clave foránea hacia `nutrition_plans`**: es una referencia entre dos agregados por su id, como la de un plato a una receta (ADR 0016).
- **`PlanImportMapper` comprueba cada valor al leer**, con la ruta del dato corrupto. Las funciones de lectura defensiva se han extraído de `NutritionPlanMapper` al *trait* `ReadsStoredJson`, que comparten los dos.

## Consecuencias

- `NutritionPlan` no cambia: el calendario, el histórico y la API siguen trabajando solo con planes completos.
- Cualquier regla nueva del plan (por ejemplo, las de los intercambios en el nivel 8) se aplicará también a las importaciones, porque confirmar pasa por `RegisterNutritionPlan`.
- Los errores del contenido de los Excel que solo detecta el dominio aparecen al confirmar, no al subir cada documento.
- Queda documentado el caso, muy improbable, de un fallo entre los dos guardados al confirmar.
- La API HTTP de la importación y la prueba de punta a punta con el servicio real son de HEXM-0033. La lectura de una importación (para mostrar su estado) también se añadirá allí, cuando haya quien la consuma.
