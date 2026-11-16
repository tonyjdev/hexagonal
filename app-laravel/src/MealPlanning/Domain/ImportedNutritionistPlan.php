<?php

declare(strict_types=1);

namespace MealPlanning\Domain;

/**
 * ImportedNutritionistPlan: lo que una importación ha leído del Excel de la
 * nutricionista: número de plan, pautas fijas, intercambios con sus reglas y
 * la semana 1 (menús 1-6).
 *
 * CONCEPTO: los datos de un documento dentro del dominio, sin interpretarlos
 * Es la misma información que NutritionistPlanReading (la salida del puerto
 * PlanReader), pero aquella vive en Application y el dominio no puede
 * usarla: las dependencias solo apuntan hacia dentro. Este value object es
 * su versión del dominio, la que guarda el agregado PlanImport.
 *
 * DECISIÓN: datos primitivos, no FixedMeal ni MenuWeek (ADR 0025)
 * Guarda el contenido con la forma del contrato del alta de un plan
 * (ADR 0014), sin convertirlo en objetos del dominio. Así, al confirmar la
 * importación, el contenido se copia tal cual a RegisterNutritionPlanCommand
 * y el plan se construye con EL MISMO código que el alta manual: las mismas
 * reglas y los mismos mensajes, y un plan igual con los mismos datos. Si se
 * construyeran aquí los MenuWeek, habría dos traducciones del documento al
 * dominio que mantener iguales.
 *
 * El precio: un menú que le falte al Excel no se detecta al subirlo, sino al
 * confirmar. Es aceptable porque el servicio importer ya comprueba la FORMA
 * del documento al leerlo (UnreadableDocument), y las reglas del plan solo
 * tienen sentido con el plan entero.
 *
 * Lo único que sí se interpreta es el número, como PlanNumber: es lo que
 * identifica la importación para el usuario («la importación del plan 27»).
 *
 * CAPA: Dominio (MealPlanning\Domain).
 */
final readonly class ImportedNutritionistPlan
{
    /**
     * @param  list<array{meal_type: string, description: string|null, option_groups: list<array{choose: int, options: list<string>}>}>  $fixedMeals
     * @param  list<array{category: string, food: string, serving: string}>  $exchanges
     * @param  list<string>  $exchangeRules  en el orden del documento
     * @param  list<array{menu: int, lunch: string, dinner: string}>  $nutritionistWeek
     */
    private function __construct(
        private PlanNumber $number,
        private array $fixedMeals,
        private array $exchanges,
        private array $exchangeRules,
        private array $nutritionistWeek,
    ) {}

    /**
     * @param  list<array{meal_type: string, description: string|null, option_groups: list<array{choose: int, options: list<string>}>}>  $fixedMeals
     * @param  list<array{category: string, food: string, serving: string}>  $exchanges
     * @param  list<string>  $exchangeRules
     * @param  list<array{menu: int, lunch: string, dinner: string}>  $nutritionistWeek
     */
    public static function of(
        PlanNumber $number,
        array $fixedMeals,
        array $exchanges,
        array $exchangeRules,
        array $nutritionistWeek,
    ): self {
        return new self($number, $fixedMeals, $exchanges, $exchangeRules, $nutritionistWeek);
    }

    public function number(): PlanNumber
    {
        return $this->number;
    }

    /**
     * @return list<array{meal_type: string, description: string|null, option_groups: list<array{choose: int, options: list<string>}>}>
     */
    public function fixedMeals(): array
    {
        return $this->fixedMeals;
    }

    /**
     * @return list<array{category: string, food: string, serving: string}>
     */
    public function exchanges(): array
    {
        return $this->exchanges;
    }

    /**
     * @return list<string>
     */
    public function exchangeRules(): array
    {
        return $this->exchangeRules;
    }

    /**
     * @return list<array{menu: int, lunch: string, dinner: string}>
     */
    public function nutritionistWeek(): array
    {
        return $this->nutritionistWeek;
    }
}
