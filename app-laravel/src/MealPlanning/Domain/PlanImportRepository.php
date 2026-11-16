<?php

declare(strict_types=1);

namespace MealPlanning\Domain;

/**
 * PlanImportRepository: dónde se guardan las importaciones de planes.
 *
 * CONCEPTO: puerto de salida, un repositorio por agregado
 * PlanImport es un agregado propio (ADR 0025), así que tiene su propio
 * repositorio, distinto del de los planes: cada repositorio guarda y
 * recupera un agregado ENTERO, y no hay uno «de MealPlanning» para todo.
 * Adaptadores: InMemoryPlanImportRepository (tests) y
 * EloquentPlanImportRepository (PostgreSQL, una fila con documentos jsonb,
 * como los planes, ADR 0013); los dos cumplen los mismos tests de contrato
 * (PlanImportRepositoryContractTest).
 *
 * Sin reglas entre importaciones: puede haber varias pendientes a la vez
 * (decisión de HEXM-0032). Las reglas entre PLANES (número e inicio únicos)
 * se comprueban al confirmar, cuando el plan se guarda en su repositorio.
 *
 * CAPA: Dominio (MealPlanning\Domain).
 */
interface PlanImportRepository
{
    /**
     * Guarda la importación entera (nueva o una versión nueva de la misma),
     * reemplazando la anterior si ya existía una con el mismo id.
     */
    public function save(PlanImport $import): void;

    /**
     * La importación con ese id, o null si no existe.
     */
    public function find(PlanImportId $id): ?PlanImport;
}
