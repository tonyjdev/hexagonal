<?php

declare(strict_types=1);

namespace MealPlanning\Infrastructure\Persistence\Eloquent;

use MealPlanning\Domain\PlanImport;
use MealPlanning\Domain\PlanImportId;
use MealPlanning\Domain\PlanImportRepository;

/**
 * EloquentPlanImportRepository: guarda las importaciones de planes en
 * PostgreSQL, una fila por importación con documentos jsonb (ADR 0025).
 *
 * Segundo adaptador del puerto PlanImportRepository, con los mismos tests de
 * contrato que InMemoryPlanImportRepository. Más sencillo que el de los
 * planes: no hay reglas entre importaciones (puede haber varias pendientes)
 * ni índices únicos que traducir a errores del dominio.
 *
 * Atomicidad: la importación entera es UNA fila, y updateOrCreate() acaba en
 * un único INSERT o UPDATE, que PostgreSQL ya hace atómico (como en
 * EloquentNutritionPlanRepository). Aquí no hace falta el SAVEPOINT de los
 * planes: no hay ninguna violación esperada después de la cual seguir
 * consultando.
 *
 * CAPA: Infraestructura (MealPlanning\Infrastructure).
 */
final readonly class EloquentPlanImportRepository implements PlanImportRepository
{
    public function __construct(private PlanImportMapper $mapper = new PlanImportMapper) {}

    public function save(PlanImport $import): void
    {
        $row = $this->mapper->toRow($import);

        PlanImportModel::query()->updateOrCreate(['id' => $row['id']], $row);
    }

    public function find(PlanImportId $id): ?PlanImport
    {
        $model = PlanImportModel::query()->find($id->value());

        return $model === null ? null : $this->mapper->toDomain($model);
    }
}
