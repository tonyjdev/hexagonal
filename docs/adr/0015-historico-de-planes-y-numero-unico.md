# 0015. Histórico de planes y número de plan único

**Fecha:** 2026-11-05 · **Estado:** aceptada · **Tarea:** HEXM-0023 · **Relacionado:** [ADR 0008](0008-consultas-dto-de-lectura-y-no-encontrado.md), [ADR 0010](0010-limites-del-agregado-nutritionplan-y-vigencia.md), [ADR 0011](0011-reglas-entre-agregados-en-el-contrato-del-repositorio.md), [ADR 0013](0013-persistencia-del-agregado-nutritionplan-como-documento.md)

## Contexto

Cuando llega un plan nuevo, el anterior deja de estar vigente, pero conviene conservarlo («¿qué cenaba en el plan 25?»). Para el histórico había que decidir:

1. **Qué devuelve el puerto** para listar todos los planes: los agregados completos o un modelo de lectura propio, solo con el número, el id y el inicio.
2. **Dónde se calcula la vigencia de cada plan** (desde cuándo y hasta cuándo rige), que no se guarda (ADR 0010).
3. **Si el número de plan es único.** Lo dejó abierto el `domain_issue` de HEXM-0021: al repetir un alta, el mensaje «ese día ya empieza el plan 27» dejó a la vista que nada impedía dos planes con el mismo número, y el histórico los muestra por número.

## Decisión

1. **`NutritionPlanRepository::history(): list<NutritionPlan>`**: todos los planes, del más reciente al más antiguo por fecha de inicio.
   - Devuelve **agregados completos**: una persona tiene unos pocos planes al año, y se cargan en una sola consulta. Sigue siendo CQS y no CQRS, como el recetario (ADR 0008).
   - Forma parte del contrato y la cumplen los dos adaptadores.
2. **La vigencia es una regla del dominio**, en dos piezas nuevas de `MealPlanning\Domain`:
   - `ValidityPeriod`, un *value object*: el inicio y el último día en que rige (inclusivo), o sin fin;
   - `NutritionPlanHistory`: ordena los planes y deriva el periodo de cada uno. Cada plan termina el día antes de que empiece el siguiente, y el más reciente no tiene fin.

   El repositorio solo devuelve los planes, sin calcular nada.
3. **El estado respecto a hoy** («vigente», «finalizado», «próximo», con el enum `PlanStatus`) se calcula en la consulta `ListNutritionPlans` con el `Clock`. No se guarda: depende de qué día se pregunte.
4. **El número de plan es único** (regla 22). `save()` rechaza otro plan (con distinto id) con el mismo número, mediante `DuplicatedPlanNumber`.
   - Es una regla más entre agregados en el contrato del puerto (ADR 0011). En PostgreSQL la garantiza un índice único, añadido con una **migración nueva**, sin editar la de HEXM-0020.
   - Volver a guardar el mismo plan sigue permitido.
   - Si un plan choca a la vez por inicio y por número, **gana `DuplicatedPlanStart`**: el orden es parte del contrato y no depende de qué índice compruebe antes PostgreSQL.
5. **Dos consultas y sus rutas**:
   - `ListNutritionPlans` → `GET /api/nutrition-plans`, cada plan con su `url`;
   - `GetNutritionPlan` → `GET /api/nutrition-plans/{id}`, que devuelve `NutritionPlanView`, o `PlanNotFound` (404), o `InvalidNutritionPlanId` (422).

   El alta añade ya la cabecera `Location`.

## Consecuencias

- **A favor:**
  - El fin de la vigencia no puede quedar desincronizado, porque no se guarda: registrar un plan nuevo cierra el anterior sin tocarlo.
  - La regla 19 está escrita una vez, en el dominio, y se prueba sin Laravel.
  - «¿Qué cenaba en el plan 25?» tiene una sola respuesta.
- **En contra:**
  - El listado reconstruye planes enteros solo para mostrar su número y sus fechas. Con decenas de planes no importa; con miles, convendría un modelo de lectura (CQRS) que lea solo tres columnas.
  - Si alguna base de datos ya tuviera dos planes con el mismo número, la migración fallaría al crear el índice. Es intencionado: una regla nueva no se acepta en silencio sobre datos que la incumplen.
- **Revisar cuando:**
  - el histórico crezca o se pagine: entonces habrá que pasar a un puerto de lectura propio;
  - la nutricionista reedite un plan conservando su número: entonces habrá que distinguir versiones del mismo plan.
