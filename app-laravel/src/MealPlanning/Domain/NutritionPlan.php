<?php

declare(strict_types=1);

namespace MealPlanning\Domain;

use DateTimeImmutable;

/**
 * NutritionPlan: un plan nutricional de la nutricionista ("plan 27"), con
 * todo lo que hace falta para saber qué se come cada día.
 *
 *     NutritionPlan (raíz)             ← NutritionPlanId, PlanNumber, startsOn
 *     ├── FixedMeal ×0..3              ← desayuno, media mañana, media tarde
 *     ├── ExchangeTable                ← intercambios y sus reglas
 *     └── MenuWeek ×2 (semana 1 y 2)   ← entidades locales
 *         └── MenuDay ×6               ← comida + cena (Dish)
 *
 * CONCEPTO: límites del agregado (ADR 0010)
 * Un agregado es la FRONTERA DE CONSISTENCIA: lo que hay dentro se crea, se
 * valida, se guarda y se carga junto, a través de la raíz. La pregunta de
 * diseño es qué entra y qué se queda fuera, referenciado solo por su id:
 *
 *   - DENTRO: las dos semanas (con sus días), las pautas fijas y la tabla
 *     de intercambios. Hay reglas que miran varias partes a la vez ("el plan
 *     tiene exactamente la semana 1 y la 2"; en el nivel 8, "la semana 2
 *     respeta los intercambios del plan"), y solo se pueden garantizar
 *     siempre si viven dentro de UNA frontera. Además, el calendario
 *     (nivel 4) necesita el plan entero, y es pequeño: 12 días.
 *   - FUERA: las recetas. Un plato referenciará una receta solo por su
 *     RecipeId (nivel 6); la receta es OTRO agregado, de otro contexto.
 *   - FUERA: los demás planes. "Solo un plan vigente en cada fecha" (regla
 *     5) afecta a VARIOS planes, así que ningún plan la puede garantizar
 *     solo: la resolverá el nivel 5, consultando el repositorio.
 *
 * La alternativa (MenuWeek como agregado aparte, con su repositorio) dejaba
 * la regla "exactamente dos semanas" fuera de todo agregado. Tamaño frente
 * a consistencia: aquí el tamaño no es un problema y la consistencia sí.
 *
 * CONCEPTO: vigencia derivada, sin fecha de fin (ADR 0010)
 * El plan solo guarda cuándo EMPIEZA. Cuándo deja de estar vigente se deduce:
 * cuando empieza el siguiente. "El plan vigente el día X" será el de fecha de
 * inicio más reciente que no pase de X. Guardar también una fecha de fin
 * duplicaría el dato (el fin de un plan = el inicio del siguiente) y habría
 * que mantener las dos copias coherentes.
 *
 * CONCEPTO: tiempo en el dominio con PHP nativo
 * La fecha de inicio es un DateTimeImmutable de PHP, no un Carbon: el núcleo
 * no depende de Laravel ni de sus librerías (ADR 0004). Inmutable porque un
 * DateTime normal se puede cambiar "por detrás" ($date->modify('+1 day'))
 * desde quien guarde la misma referencia; un DateTimeImmutable devuelve uno
 * nuevo en cada cambio. Solo importa el DÍA: la hora se pone a medianoche.
 *
 * CONCEPTO: entidad inmutable (por ahora)
 * Es una entidad (se compara por su NutritionPlanId), pero hoy ningún caso de
 * uso la cambia, así que la clase entera es readonly y no tiene ni un método
 * que la modifique. Cuando haga falta (editar la semana 2 en el nivel 8),
 * llegará un método de negocio que compruebe las invariantes, nunca un
 * setter. YAGNI: no se escribe hoy lo que nadie usa.
 *
 * CONCEPTO: el agregado no expone su interior para modificarlo
 * Los getters devuelven value objects inmutables, entidades readonly o
 * COPIAS de los arrays internos (los arrays de PHP se copian por valor): con
 * lo que sale de aquí se puede leer todo, pero no cambiar nada del plan.
 *
 * INVARIANTES:
 *   1. Exactamente dos semanas, la 1 y la 2, sin repetir (regla 1).
 *   2. La fecha de inicio cae en lunes (las semanas empiezan en lunes).
 *   3. Como mucho una pauta fija por tipo de comida (un solo desayuno...).
 *      Ninguna es obligatoria: el documento puede no traer media tarde.
 *
 * CAPA: Dominio (MealPlanning\Domain).
 */
final readonly class NutritionPlan
{
    /**
     * Privado: solo se crea con NutritionPlan::create().
     *
     * @param  list<FixedMeal>  $fixedMeals  en el orden del día (MealType)
     * @param  list<MenuWeek>  $weeks  la semana 1 y la semana 2, en ese orden
     */
    private function __construct(
        private NutritionPlanId $id,
        private PlanNumber $number,
        private DateTimeImmutable $startsOn,
        private array $fixedMeals,
        private ExchangeTable $exchangeTable,
        private array $weeks,
    ) {}

    /**
     * Crea un plan comprobando todas sus invariantes. Las semanas y las
     * pautas pueden llegar en cualquier orden; se guardan en el canónico.
     *
     * DECISIÓN: el plan no exige que la semana 1 sea de la nutricionista y
     * la 2 del usuario, por coherencia con MenuWeek::create() (HEXM-0013):
     * es una costumbre del documento que ya fijan los constructores de
     * formato, no una regla que haya pedido el negocio.
     *
     * @param  array<FixedMeal>  $fixedMeals
     * @param  array<MenuWeek>  $weeks
     */
    public static function create(
        NutritionPlanId $id,
        PlanNumber $number,
        DateTimeImmutable $startsOn,
        array $fixedMeals,
        ExchangeTable $exchangeTable,
        array $weeks,
    ): self {
        // Medianoche: un plan empieza un DÍA, no a una hora concreta.
        $startsOn = $startsOn->setTime(0, 0);

        if (Weekday::fromDate($startsOn) !== Weekday::Monday) {
            throw InvalidNutritionPlan::startsOnNonMonday($startsOn);
        }

        return new self(
            $id,
            $number,
            $startsOn,
            self::fixedMealsInDayOrder($fixedMeals),
            $exchangeTable,
            self::bothWeeksInOrder($weeks),
        );
    }

    public function id(): NutritionPlanId
    {
        return $this->id;
    }

    public function number(): PlanNumber
    {
        return $this->number;
    }

    /**
     * El lunes en que empieza el plan (y su semana 1), a medianoche.
     */
    public function startsOn(): DateTimeImmutable
    {
        return $this->startsOn;
    }

    /**
     * Las pautas fijas del plan, en el orden del día (desayuno, media
     * mañana, media tarde). Devuelve una COPIA del array.
     *
     * @return list<FixedMeal>
     */
    public function fixedMeals(): array
    {
        return $this->fixedMeals;
    }

    /**
     * La pauta de un tipo de comida, o null si el plan no la trae (o si se
     * pregunta por la comida o la cena, que no son pautas fijas).
     */
    public function fixedMeal(MealType $mealType): ?FixedMeal
    {
        foreach ($this->fixedMeals as $fixedMeal) {
            if ($fixedMeal->mealType() === $mealType) {
                return $fixedMeal;
            }
        }

        return null;
    }

    public function exchangeTable(): ExchangeTable
    {
        return $this->exchangeTable;
    }

    /**
     * Las dos semanas, la 1 y luego la 2. Devuelve una COPIA del array.
     *
     * @return list<MenuWeek>
     */
    public function weeks(): array
    {
        return $this->weeks;
    }

    /**
     * La semana 1 o la 2. Es lo que preguntará el calendario (nivel 4) una
     * vez sepa qué semana toca en una fecha. Cualquier otro número es un
     * error de quien llama, con el mismo mensaje que MenuWeek.
     */
    public function week(int $number): MenuWeek
    {
        foreach ($this->weeks as $week) {
            if ($week->number() === $number) {
                return $week;
            }
        }

        throw InvalidMenuWeek::unknownNumber($number);
    }

    /**
     * Igualdad por IDENTIDAD, como Recipe: dos objetos NutritionPlan son el
     * mismo plan si tienen el mismo NutritionPlanId.
     */
    public function equals(self $other): bool
    {
        return $this->id->equals($other->id);
    }

    /**
     * Comprueba que están la semana 1 y la 2, sin repetir, y las devuelve en
     * ese orden.
     *
     * MenuWeek ya garantiza que su número es 1 o 2. Por eso basta con mirar
     * repetidas y luego cuál falta: sin repetidas, nunca puede haber más de
     * dos.
     *
     * @param  array<MenuWeek>  $weeks
     * @return list<MenuWeek>
     */
    private static function bothWeeksInOrder(array $weeks): array
    {
        $byNumber = [];

        foreach ($weeks as $week) {
            if (isset($byNumber[$week->number()])) {
                throw InvalidNutritionPlan::duplicatedWeek($week->number());
            }

            $byNumber[$week->number()] = $week;
        }

        foreach ([1, 2] as $number) {
            if (! isset($byNumber[$number])) {
                throw InvalidNutritionPlan::missingWeek($number);
            }
        }

        return [$byNumber[1], $byNumber[2]];
    }

    /**
     * Comprueba que no se repite ninguna pauta y las ordena según el orden
     * del día, que es el de MealType::cases().
     *
     * @param  array<FixedMeal>  $fixedMeals
     * @return list<FixedMeal>
     */
    private static function fixedMealsInDayOrder(array $fixedMeals): array
    {
        $byType = [];

        foreach ($fixedMeals as $fixedMeal) {
            $type = $fixedMeal->mealType();

            if (isset($byType[$type->value])) {
                throw InvalidNutritionPlan::duplicatedFixedMeal($type);
            }

            $byType[$type->value] = $fixedMeal;
        }

        $inDayOrder = [];

        foreach (MealType::cases() as $type) {
            if (isset($byType[$type->value])) {
                $inDayOrder[] = $byType[$type->value];
            }
        }

        return $inDayOrder;
    }
}
