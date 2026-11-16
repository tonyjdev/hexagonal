<?php

declare(strict_types=1);

namespace MealPlanning\Application;

use MealPlanning\Domain\ImportedNutritionistPlan;
use MealPlanning\Domain\ImportedSelfWeek;
use MealPlanning\Domain\PlanNumber;

/**
 * ImportedDocuments: convierte lo que devuelve el puerto PlanReader (DTO de
 * Application) en lo que guarda el agregado PlanImport (value objects del
 * dominio).
 *
 * CONCEPTO: la frontera entre capas también se cruza traduciendo
 * NutritionistPlanReading y ImportedNutritionistPlan llevan los mismos datos,
 * pero viven en capas distintas: el primero es la salida de un puerto de
 * Application, y el dominio no puede depender de él. Traducir es copiar
 * campo a campo; lo único que se interpreta es el número de plan
 * (PlanNumber::of(), que rechaza un número que no sea positivo).
 *
 * Es una clase aparte, y no un método privado de cada caso de uso, porque la
 * misma traducción la necesitan dos: empezar una importación y sustituir su
 * Excel de la nutricionista.
 *
 * CAPA: Aplicación (MealPlanning\Application).
 */
final class ImportedDocuments
{
    public static function nutritionistPlan(NutritionistPlanReading $reading): ImportedNutritionistPlan
    {
        return ImportedNutritionistPlan::of(
            PlanNumber::of($reading->number),
            $reading->fixedMeals,
            $reading->exchanges,
            $reading->exchangeRules,
            $reading->nutritionistWeek,
        );
    }

    public static function selfWeek(SelfWeekReading $reading): ImportedSelfWeek
    {
        return ImportedSelfWeek::of($reading->selfWeek);
    }
}
