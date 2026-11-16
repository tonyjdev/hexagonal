<?php

declare(strict_types=1);

namespace Tests\Support\MealPlanning;

use DateTimeImmutable;
use MealPlanning\Application\ImportedDocuments;
use MealPlanning\Application\RegisterNutritionPlanCommand;
use MealPlanning\Domain\ImportedNutritionistPlan;
use MealPlanning\Domain\ImportedSelfWeek;
use MealPlanning\Domain\NutritionPlanId;
use MealPlanning\Domain\PlanImport;
use MealPlanning\Domain\PlanImportId;
use MealPlanning\Domain\PlanNumber;

/**
 * PlanImportMother: importaciones de planes de ejemplo para los tests
 * (Object Mother, como NutritionPlanMother).
 *
 * El contenido sale del contrato con el servicio importer (ImporterContract):
 * son respuestas reales del servicio con los Excel ficticios (el plan 99).
 * Así los tests de la importación usan lo mismo que llegará de verdad, y no
 * datos escritos a mano que podrían no parecerse.
 *
 * CAPA: tests (apoyo).
 */
final class PlanImportMother
{
    /**
     * Lo leído del Excel de la nutricionista del contrato, con otro número si
     * se pide (para tener dos versiones distintas del mismo documento).
     */
    public static function nutritionistPlan(?int $number = null): ImportedNutritionistPlan
    {
        $imported = ImportedDocuments::nutritionistPlan(ImporterContract::nutritionistPlanReading());

        if ($number === null) {
            return $imported;
        }

        return ImportedNutritionistPlan::of(
            PlanNumber::of($number),
            $imported->fixedMeals(),
            $imported->exchanges(),
            $imported->exchangeRules(),
            $imported->nutritionistWeek(),
        );
    }

    public static function selfWeek(): ImportedSelfWeek
    {
        return ImportedDocuments::selfWeek(ImporterContract::selfWeekReading());
    }

    /**
     * Pendiente de la semana 2: solo con el Excel de la nutricionista.
     */
    public static function awaitingSelfWeek(?PlanImportId $id = null): PlanImport
    {
        return PlanImport::start($id ?? PlanImportId::generate(), self::nutritionistPlan());
    }

    /**
     * Con los dos Excel, lista para confirmar.
     */
    public static function readyToConfirm(?PlanImportId $id = null): PlanImport
    {
        return self::awaitingSelfWeek($id)->attachSelfWeek(self::selfWeek());
    }

    /**
     * Confirmada como el plan que se le dé (o uno cualquiera).
     */
    public static function confirmed(?PlanImportId $id = null, ?NutritionPlanId $plan = null): PlanImport
    {
        return self::readyToConfirm($id)->confirm($plan ?? NutritionPlanId::generate());
    }

    /**
     * El comando del alta MANUAL con los mismos datos que el contrato: lo que
     * enviaría quien copiase los dos Excel a mano en POST /api/nutrition-plans.
     * Se construye directamente desde los ejemplos del contrato, sin pasar
     * por la importación, para poder comparar los dos caminos.
     */
    public static function manualRegisterCommand(string $startsOn = '2026-11-02'): RegisterNutritionPlanCommand
    {
        $nutritionist = ImporterContract::nutritionistPlanReading();

        return new RegisterNutritionPlanCommand(
            number: $nutritionist->number,
            startsOn: new DateTimeImmutable($startsOn),
            fixedMeals: $nutritionist->fixedMeals,
            exchanges: $nutritionist->exchanges,
            exchangeRules: $nutritionist->exchangeRules,
            nutritionistWeek: $nutritionist->nutritionistWeek,
            selfWeek: ImporterContract::selfWeekReading()->selfWeek,
        );
    }
}
