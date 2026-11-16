<?php

declare(strict_types=1);

namespace MealPlanning\Infrastructure\Importer;

use UnexpectedValueException;

/**
 * InvalidImporterResponse: el JSON del servicio importer no cumple el contrato.
 *
 * Es un error INTERNO del adaptador, con detalle técnico y en el idioma del
 * programador: qué campo falla y por qué («nutritionist_week.2.lunch: debía
 * ser un texto»). Nunca sale del adaptador tal cual: HttpPlanReader lo
 * envuelve en PlanReaderUnavailable (el error del puerto, para el usuario) y
 * queda como su $previous, que es lo que se lee en el log.
 *
 * CAPA: Infraestructura (MealPlanning\Infrastructure\Importer).
 */
final class InvalidImporterResponse extends UnexpectedValueException
{
    public static function at(string $path, string $problem): self
    {
        return new self(sprintf('La respuesta del importador no cumple el contrato (contracts/importer) en «%s»: %s.', $path, $problem));
    }
}
