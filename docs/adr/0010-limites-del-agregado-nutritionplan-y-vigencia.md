# 0010. Límites del agregado NutritionPlan y vigencia derivada

**Fecha:** 2026-11-03 · **Estado:** aceptada · **Tarea:** HEXM-0015 · **Relacionado:** [ADR 0004](0004-lista-blanca-de-dependencias-en-el-nucleo.md), [ADR 0006](0006-persistencia-del-agregado-recipe.md)

## Contexto

En HEXM-0012 a 0014 se construyeron las piezas de un plan nutricional:

- los value objects y enums básicos;
- `MenuWeek` (entidad local) y `MenuDay` (value object);
- las pautas fijas (`FixedMeal`) y la tabla de intercambios (`ExchangeTable`).

Faltaba juntarlas en `NutritionPlan` y decidir dos cosas:

1. **Los límites del agregado.** ¿`MenuWeek` y `MenuDay` viven dentro de `NutritionPlan`, o las semanas son agregados aparte con su propio id y repositorio?
2. **La vigencia.** La regla 5 dice que solo hay un plan vigente en cada fecha y que un plan nuevo cierra la vigencia del anterior. ¿Se guarda una fecha de fin o se calcula?

Un agregado es la **frontera de consistencia**: lo que hay dentro se crea, se valida, se guarda y se carga junto, a través de su raíz. Cuanto más grande, más reglas puede garantizar siempre, pero más pesa cargarlo y más conflictos hay al guardarlo. Lo que queda fuera se referencia solo por su id y su consistencia con el agregado ya no es inmediata.

## Decisión

1. **Un único agregado.** `NutritionPlan` es la raíz y contiene:
   - sus dos `MenuWeek`, entidades locales cuya identidad es su número (1 o 2) dentro del plan;
   - los `MenuDay` de cada semana y sus `Dish`;
   - sus `FixedMeal` y su `ExchangeTable`.

   Nada de eso tiene repositorio propio ni se puede obtener sin pasar por el plan.
2. **Fuera del agregado** quedan:
   - **las recetas**: otro agregado de otro contexto, que un plato referenciará solo por su `RecipeId` (nivel 6);
   - **los demás planes**.
3. **Vigencia derivada, sin fecha de fin.** El plan solo guarda `startsOn`. El plan vigente en una fecha X es el de `startsOn` más reciente que sea ≤ X. Esa consulta afecta a varios planes, así que no es del agregado: la resolverá el nivel 5 a través del repositorio.
4. **Invariantes del agregado:**
   - exactamente dos semanas, la 1 y la 2, sin repetir;
   - `startsOn` cae en lunes (se guarda solo el día, a medianoche, como `DateTimeImmutable` nativo, sin Carbon: ADR 0004);
   - como mucho una pauta fija por tipo de comida.

   No se exige que la semana 1 sea de la nutricionista y la 2 del usuario, por coherencia con `MenuWeek::create()` (HEXM-0013).
5. **Inmutable por ahora.** Hoy ningún caso de uso cambia un plan, así que la clase es `readonly` y no tiene métodos de cambio (YAGNI). Los cambios futuros, como editar la semana 2 en el nivel 8, llegarán como métodos de negocio de la raíz, nunca como setters ni modificando una semana por fuera.

## Consecuencias

- **A favor:**
  - Las reglas que afectan a varias partes del plan se garantizan siempre, en un único sitio:
    - hoy, «la semana 1 y la 2»;
    - en el nivel 8, «la semana 2 respeta los intercambios del plan».
  - El calendario del nivel 4 recibe un plan completo y coherente.
  - No hay dos copias de la misma fecha que mantener sincronizadas: el fin de un plan es siempre el inicio del siguiente.
- **En contra:**
  - Cargar un plan trae siempre todo, aunque solo se necesite un día. Con 12 días, unas pocas pautas y una tabla de intercambios, el coste es despreciable.
  - Para saber si un plan está vigente hay que mirar los demás. No se puede preguntar al plan suelto («¿sigues vigente?»).
  - Al persistirlo (nivel 5), un agregado de cuatro niveles necesitará varias tablas y guardarse en una transacción, como `Recipe` (ADR 0006), pero más grande.
- **Revisar cuando:**
  - Aparezca la necesidad de **cerrar un plan sin que empiece otro** (por ejemplo, una pausa). Entonces la fecha de fin dejaría de poder derivarse.
  - El tamaño del plan crezca mucho o se editen sus partes de forma concurrente. Entonces habría que valorar sacar las semanas a un agregado propio.
