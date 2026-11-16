<?php

declare(strict_types=1);

namespace MealPlanning\Application;

use MealPlanning\Domain\PlanImportId;
use Shared\Application\NotFound;

/**
 * PlanImportNotFound: no existe ninguna importación de plan con ese
 * identificador.
 *
 * Como PlanNotFound: hereda de NotFound (→ HTTP 404, ADR 0008). La lanzan
 * los casos de uso que continúan una importación (cambiar un documento o
 * confirmarla) cuando el repositorio devuelve null.
 *
 * CAPA: Aplicación (MealPlanning\Application).
 */
final class PlanImportNotFound extends NotFound
{
    public static function withId(PlanImportId $id): self
    {
        return new self(sprintf('No existe ninguna importación de plan con el identificador «%s».', $id->value()));
    }
}
