# 0011. Reglas entre agregados en el contrato del repositorio

**Fecha:** 2026-11-03 · **Estado:** aceptada · **Tarea:** HEXM-0018 · **Relacionado:** [ADR 0006](0006-persistencia-del-agregado-recipe.md), [ADR 0010](0010-limites-del-agregado-nutritionplan-y-vigencia.md)

## Contexto

El ADR 0010 dejó fuera del agregado `NutritionPlan` las reglas que comparan varios planes:

- **la vigencia**: el plan vigente en una fecha es el de inicio más reciente que no pasa de ella;
- **«solo un plan vigente en cada fecha»** (regla 5).

Ningún plan puede garantizarlas por sí solo. Al crear el puerto `NutritionPlanRepository` (HEXM-0018) había que decidir:

1. dónde se responde «¿qué plan está vigente el día X?»;
2. qué pasa si se guardan dos planes **distintos** que empiezan el mismo día, porque entonces la vigencia sería ambigua.

Opciones barajadas para el segundo punto:

- **(a)** el repositorio rechaza el segundo plan al guardarlo;
- **(b)** se permiten los dos y gana el de número más alto;
- **(c)** el repositorio lo permite y lo comprueba un futuro caso de uso «registrar plan».

## Decisión

1. **La vigencia es una consulta del puerto con nombre del negocio**: `inForceOn(DateTimeImmutable): ?NutritionPlan`, y no un `findBy` genérico. Compara fechas del calendario (año-mes-día en la zona de cada fecha, sin hora), igual que `MealCalendar`.
2. **Opción (a): `save()` rechaza otro plan (con distinto id) que empiece el mismo día que uno ya guardado.** Lo hace con la excepción de dominio `DuplicatedPlanStart`, con su mensaje en español. Volver a guardar el mismo plan (mismo id) lo reemplaza, aunque cambie su fecha.
3. **Estas dos reglas forman parte del contrato del puerto.** Se escriben en la interfaz y se comprueban con tests de contrato (`NutritionPlanRepositoryContractTest`), que deberá pasar cada adaptador:
   - el de memoria, con un bucle en PHP;
   - el de Eloquent (nivel 5), con `WHERE starts_on <= ? ORDER BY starts_on DESC LIMIT 1` y un **índice único** sobre la fecha de inicio. La violación de ese índice se traducirá a la misma `DuplicatedPlanStart`.

## Consecuencias

- **A favor:**
  - La regla 5 se cumple siempre en lo que está guardado: nunca hay dos planes empatados ni una vigencia ambigua.
  - En PostgreSQL, un índice único hace que la regla aguante también la concurrencia (dos guardados a la vez), algo que una comprobación previa en un caso de uso no garantiza.
  - El caso de uso `GetMenuForDate` (HEXM-0019) solo pregunta `inForceOn()`, sin saber cómo se calcula.
  - Los tests de contrato evitan que el adaptador en memoria y el de Eloquent se comporten distinto.
- **En contra:**
  - Una regla de negocio se **ejecuta** en los adaptadores, no en una clase del dominio, y cada adaptador nuevo tiene que reimplementarla. Lo compensan los tests de contrato: la regla está escrita una vez como especificación.
  - El error lo lanza el adaptador, así que en el nivel 5 hay que traducir la excepción de la base de datos a la del dominio.
  - Corregir la fecha de un plan pasa por guardarlo de nuevo con el mismo id. Si coincide con la de otro plan, se rechaza igualmente.
- **Revisar cuando:**
  - aparezca otra regla entre planes, como solapes o huecos entre planes, o un plan que se cierra sin que empiece otro (ver ADR 0010). Quizá entonces convenga un servicio de dominio que reciba los planes implicados, o un agregado de nivel superior («historial de planes»).
