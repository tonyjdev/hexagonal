<?php

declare(strict_types=1);

namespace MealPlanning\Infrastructure;

use MealPlanning\Domain\PlanImport;
use MealPlanning\Domain\PlanImportId;
use MealPlanning\Domain\PlanImportRepository;

/**
 * InMemoryPlanImportRepository: guarda las importaciones en un array de PHP.
 *
 * Adaptador en memoria del puerto PlanImportRepository (como
 * InMemoryNutritionPlanRepository): con él se prueban los casos de uso de la
 * importación sin base de datos, y cumple los mismos tests de contrato que
 * el de Eloquent. Sin copias defensivas: PlanImport es inmutable.
 *
 * CAPA: Infraestructura (MealPlanning\Infrastructure).
 */
final class InMemoryPlanImportRepository implements PlanImportRepository
{
    /**
     * Importaciones guardadas, indexadas por el valor de su PlanImportId.
     *
     * @var array<string, PlanImport>
     */
    private array $imports = [];

    public function save(PlanImport $import): void
    {
        $this->imports[$import->id()->value()] = $import;
    }

    public function find(PlanImportId $id): ?PlanImport
    {
        return $this->imports[$id->value()] ?? null;
    }
}
