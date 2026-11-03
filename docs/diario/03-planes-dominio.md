# Nivel 03: Planes (dominio)

**Fecha:** 2026-11-03 · **Etiqueta git:** `nivel-03`

Tareas: HEXM-0012 (value objects de los planes), HEXM-0013 (días y semanas de menú), HEXM-0014 (pautas fijas e intercambios) y HEXM-0015 (agregado `NutritionPlan`).

## Qué se ha construido

- **El segundo contexto, `MealPlanning`**, solo con dominio puro: sin base de datos, sin HTTP y sin Laravel.
- **Las piezas básicas** (HEXM-0012):
  - los identificadores y números: `NutritionPlanId` y `PlanNumber`;
  - los enums `MealType`, `WeekSource` y `ExchangeCategory`;
  - el plato `Dish`, con sus dos formatos: texto libre en la semana 1 y nombre con ingredientes en la semana 2;
  - `DishIngredient`, con la cantidad en texto.

  El UUID v7 se movió al *shared kernel* (`Shared\Domain\Uuid`).
- **El calendario de la semana** (HEXM-0013):
  - `Weekday`, con la numeración ISO y el sábado libre;
  - `MenuDay`, un value object con la comida y la cena obligatorias;
  - `MenuWeek`, la entidad local con 6 días sin repetir, con la traducción de los menús 1-6 y los días 8-14 en constructores con nombre.
- **Lo que no cambia en todo el plan** (HEXM-0014):
  - las pautas fijas: `FixedMeal`, con grupos "elige N" (`OptionGroup` de `MealOption`);
  - la tabla de intercambios: `ExchangeTable`, agrupada por categoría y con las reglas en texto.
- **El agregado** (HEXM-0015): `NutritionPlan` reúne el id, el número, el lunes de inicio, las pautas fijas, la tabla de intercambios y exactamente la semana 1 y la 2.
- **334 tests** en el gate (eran 220 al cerrar el nivel 2).

```
NutritionPlan (raíz)  ── NutritionPlanId, PlanNumber, startsOn (lunes)
├── FixedMeal ×0..3   ── OptionGroup ── MealOption      (desayuno, media mañana, media tarde)
├── ExchangeTable     ── Exchange (por ExchangeCategory) + reglas en texto
└── MenuWeek ×2       ── número 1 o 2 (identidad local), WeekSource
    └── MenuDay ×6    ── Weekday + comida (Dish) + cena (Dish)
                              └── DishIngredient (cantidad en texto)
```

## Conceptos aprendidos

- **Límites de un agregado:** qué entra (lo que comparte una regla que debe cumplirse siempre) y qué se queda fuera, referenciado por id (las recetas) o resuelto entre agregados (el plan vigente). Es un equilibrio entre consistencia y tamaño.
- **Entidad local frente a value object:** una semana tiene identidad dentro de su plan (su número), mientras que un día es un valor que se sustituye entero.
- **Invariantes sobre colecciones:** cardinalidad (6 días, 2 semanas), unicidad (sin días, semanas ni pautas repetidas) y orden canónico, venga como venga la entrada.
- **Constructores con nombre para traducir formatos de origen** (`fromNutritionistMenus()`, `fromSelfDays()`). La regla "el menú 6 es el domingo" es del negocio, no del Excel.
- **Enums con comportamiento:**
  - `label()` y `isFixed()` con `match` sin `default`, para que un caso nuevo obligue a decidir;
  - el *int-backed enum* `Weekday`, con `fromDate()`.
- ***Shared kernel*:** qué comparten dos contextos (la técnica: `Uuid`, `DomainException`) y qué no (los conceptos: `Quantity`).
- **Datos con reglas que aún no se ejecutan:** las reglas de los intercambios se guardan como texto hasta que el nivel 8 las necesite.
- **El tiempo en el dominio con PHP nativo:** `DateTimeImmutable` sin Carbon, normalizado al día.
- **Una entidad `readonly`:** sin métodos de cambio mientras nadie los necesite (YAGNI).

## Decisiones tomadas (y por qué)

- **El UUID al *shared kernel*, y los identificadores lo envuelven en vez de heredar de él** (actualización del ADR 0005). Así hay un tipo por concepto y PHPStan no deja confundir un id de plan con uno de receta.
- **`DishIngredient` no usa el `Quantity` del recetario:** no se cruza la frontera entre contextos. Sumar cantidades para la lista de la compra se decidirá en el nivel 9.
- **`MenuDay` es un value object y `MenuWeek` una entidad local** (HEXM-0013).
- **Las reglas de los intercambios se guardan en texto** y su cumplimiento queda para el nivel 8 (HEXM-0014).
- **Un único agregado `NutritionPlan` con sus semanas y días, y la vigencia derivada de la fecha de inicio del siguiente plan, sin fecha de fin** ([ADR 0010](../adr/0010-limites-del-agregado-nutritionplan-y-vigencia.md)).
- **Ni la semana ni el plan exigen que la semana 1 sea de la nutricionista y la 2 del usuario:** es una costumbre del documento que ya fijan los constructores de formato.

## Dificultades / dudas

- **`Self` como caso de enum:** `self` es una palabra reservada, pero PHP permite `Self` como nombre de caso, que dentro del enum se escribe `self::Self`.
- **El *spread* de PHP renumera las claves enteras** (`[...$menus, 7 => …]`), y eso rompía un test de los menús 1-6. Hay que asignar la clave a mano.
- **PHP 8.5 y `#[\NoDiscard]`:** `DateTimeImmutable::modify()` avisa si se tira su resultado. En el test que lo hace a propósito se usa el nuevo cast `(void)`.
- **Las funciones auxiliares de Pest son globales:** las de `NutritionPlanTest` llevan el prefijo `plan27` para no chocar con las de `MenuWeekTest`.
- **Pendiente:**
  - "solo un plan vigente en cada fecha" (nivel 5);
  - qué me toca hoy, con `MealCalendar` y `Clock` (nivel 4);
  - cómo se edita la semana 2 a través del plan (nivel 8).

## Para profundizar

- Vaughn Vernon, *Effective Aggregate Design* (tres artículos, en dddcommunity.org): reglas para el tamaño de un agregado y por qué referenciar otros agregados por id.
- Eric Evans, *Domain-Driven Design*, capítulo 6 (agregados) y capítulo 14 (*shared kernel*).
- La documentación de PHP sobre `DateTimeImmutable` y el RFC del atributo `#[\NoDiscard]` (PHP 8.5).
