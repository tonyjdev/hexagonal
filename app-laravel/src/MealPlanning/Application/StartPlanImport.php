<?php

declare(strict_types=1);

namespace MealPlanning\Application;

use MealPlanning\Domain\PlanImport;
use MealPlanning\Domain\PlanImportId;
use MealPlanning\Domain\PlanImportRepository;

/**
 * StartPlanImport: caso de uso «empezar la importación de un plan con el
 * Excel de la nutricionista».
 *
 *     PlanDocument ──► PlanReader::readNutritionistPlan() ──► NutritionistPlanReading
 *                                                               │ ImportedDocuments
 *                                                               ▼
 *                      PlanImportRepository::save() ◄── PlanImport::start()
 *
 * CONCEPTO: un caso de uso que usa DOS puertos de salida
 * Uno para leer de fuera (PlanReader, hoy un servicio Python) y otro para
 * guardar (PlanImportRepository, hoy PostgreSQL). El caso de uso solo
 * orquesta: no sabe que hay HTTP ni SQL detrás, y en los tests los dos son
 * adaptadores en memoria.
 *
 * Si el documento no se puede leer (UnreadableDocument, 422) o el lector no
 * responde (PlanReaderUnavailable, 503), la excepción sube tal cual y no se
 * crea ninguna importación: no hay nada que deshacer, porque guardar es el
 * último paso.
 *
 * CAPA: Aplicación (MealPlanning\Application).
 */
final readonly class StartPlanImport
{
    public function __construct(
        private PlanReader $reader,
        private PlanImportRepository $imports,
    ) {}

    /**
     * @throws UnreadableDocument si el Excel no tiene la forma esperada
     * @throws PlanReaderUnavailable si el lector no está disponible
     */
    public function __invoke(PlanDocument $nutritionistDocument): PlanImportId
    {
        $reading = $this->reader->readNutritionistPlan($nutritionistDocument);

        $import = PlanImport::start(PlanImportId::generate(), ImportedDocuments::nutritionistPlan($reading));

        $this->imports->save($import);

        return $import->id();
    }
}
