<?php

declare(strict_types=1);

namespace MealPlanning\Application;

use MealPlanning\Domain\InvalidPlanImport;
use MealPlanning\Domain\PlanImportId;
use MealPlanning\Domain\PlanImportRepository;

/**
 * ReplaceImportedNutritionistPlan: caso de uso «sustituir el Excel de la
 * nutricionista de una importación pendiente» (por ejemplo, porque se
 * corrigió un error). La semana 2, si ya estaba, se conserva.
 *
 * El mismo recorrido que los demás comandos de la importación: buscar la
 * importación (o PlanImportNotFound, 404), leer el documento con el puerto,
 * pedir la transición al agregado (replaceNutritionistPlan(), que rechaza
 * una importación ya confirmada) y guardar la versión nueva.
 *
 * DECISIÓN: buscar ANTES de leer
 * Leer cuesta una llamada a un servicio externo; buscar, una consulta local.
 * Si la importación no existe, no se gasta la lectura.
 *
 * CAPA: Aplicación (MealPlanning\Application).
 */
final readonly class ReplaceImportedNutritionistPlan
{
    public function __construct(
        private PlanReader $reader,
        private PlanImportRepository $imports,
    ) {}

    /**
     * @throws PlanImportNotFound si no existe la importación
     * @throws InvalidPlanImport si ya está confirmada
     * @throws UnreadableDocument si el Excel no tiene la forma esperada
     * @throws PlanReaderUnavailable si el lector no está disponible
     */
    public function __invoke(string $importId, PlanDocument $nutritionistDocument): void
    {
        $id = PlanImportId::fromString($importId);
        $import = $this->imports->find($id) ?? throw PlanImportNotFound::withId($id);

        $reading = $this->reader->readNutritionistPlan($nutritionistDocument);

        $this->imports->save($import->replaceNutritionistPlan(ImportedDocuments::nutritionistPlan($reading)));
    }
}
