<?php

declare(strict_types=1);

namespace MealPlanning\Application;

/**
 * NutritionistPlanReading: lo que se ha leído del Excel de la nutricionista.
 *
 * Su número de plan, las pautas fijas, los intercambios con sus reglas y la
 * semana 1 (menús 1-6). Es la parte del plan que trae ese documento; la
 * semana 2 viene en el otro (SelfWeekReading) y la fecha de inicio no está
 * en ninguno (la dará el usuario al confirmar la importación: ConfirmPlanImport,
 * HEXM-0032).
 *
 * CONCEPTO: DTO de salida de un puerto, no el agregado
 * Leer un documento NO es crear un plan: falta la otra semana y la fecha, y
 * un NutritionPlan exige las dos semanas (regla 1). Por eso el puerto no
 * devuelve objetos del dominio, sino datos primitivos con la forma del
 * contrato del alta de un plan (ADR 0014). Cuando estén las dos piezas, el
 * caso de uso las juntará en un RegisterNutritionPlanCommand y el dominio
 * aplicará sus reglas como con cualquier otro alta (HEXM-0032).
 *
 * DECISIÓN: los mismos tipos que el comando (ADR 0024)
 * Cada array tiene la misma forma PHPStan que su parámetro en
 * RegisterNutritionPlanCommand, algo más estricta (aquí los platos siempre
 * son texto: el servicio no devuelve nulos). Así pasar de la lectura al
 * comando es copiar, sin traducir nada.
 *
 * CAPA: Aplicación (MealPlanning\Application): salida del puerto PlanReader.
 */
final readonly class NutritionistPlanReading
{
    /**
     * @param  list<array{meal_type: string, description: string|null, option_groups: list<array{choose: int, options: list<string>}>}>  $fixedMeals
     * @param  list<array{category: string, food: string, serving: string}>  $exchanges
     * @param  list<string>  $exchangeRules  en el orden del documento
     * @param  list<array{menu: int, lunch: string, dinner: string}>  $nutritionistWeek
     */
    public function __construct(
        public int $number,
        public array $fixedMeals,
        public array $exchanges,
        public array $exchangeRules,
        public array $nutritionistWeek,
    ) {}
}
