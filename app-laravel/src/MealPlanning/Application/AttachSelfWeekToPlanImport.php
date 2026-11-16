<?php

declare(strict_types=1);

namespace MealPlanning\Application;

use MealPlanning\Domain\InvalidPlanImport;
use MealPlanning\Domain\PlanImportId;
use MealPlanning\Domain\PlanImportRepository;

/**
 * AttachSelfWeekToPlanImport: caso de uso «añadir a una importación el Excel
 * de la semana 2», o sustituir el que ya tenía.
 *
 * Con la semana 2, la importación pasa a estar lista para confirmar
 * (PlanImportStatus::ReadyToConfirm). Recorrido y decisiones, como
 * ReplaceImportedNutritionistPlan: buscar, leer con el puerto
 * (readSelfWeek()), transición en el agregado (attachSelfWeek()) y guardar.
 *
 * DECISIÓN: añadir y sustituir son el mismo caso de uso
 * Para el usuario es el mismo gesto («esta es mi semana 2»), y para el
 * agregado, la misma transición. Dos casos de uso obligarían a quien llama a
 * saber de antemano si ya había una.
 *
 * CAPA: Aplicación (MealPlanning\Application).
 */
final readonly class AttachSelfWeekToPlanImport
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
    public function __invoke(string $importId, PlanDocument $selfWeekDocument): void
    {
        $id = PlanImportId::fromString($importId);
        $import = $this->imports->find($id) ?? throw PlanImportNotFound::withId($id);

        $reading = $this->reader->readSelfWeek($selfWeekDocument);

        $this->imports->save($import->attachSelfWeek(ImportedDocuments::selfWeek($reading)));
    }
}
