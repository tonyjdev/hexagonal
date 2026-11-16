<?php

declare(strict_types=1);

namespace MealPlanning\Infrastructure\Http;

use DateTimeImmutable;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * ConfirmPlanImportRequest: la confirmación de una importación, con la fecha
 * de inicio del plan (HEXM-0033).
 *
 *     POST /api/plan-imports/{id}/confirm   {"starts_on": "2026-11-02"}
 *
 * CONCEPTO: validación de forma, no de negocio (ADR 0007)
 * Aquí solo se exige una fecha con el formato AAAA-MM-DD. Que caiga en lunes,
 * que no empiece otro plan ese día o que no se repita el número son reglas
 * del plan: las comprueba RegisterNutritionPlan, el mismo caso de uso que el
 * alta manual, y llegan como errores de dominio (422 en español). Si se
 * validaran aquí también, habría dos sitios que mantener y el alta manual y
 * la importación podrían acabar con reglas distintas.
 *
 * Por qué `starts_on`: es la misma clave que en el alta manual de un plan
 * (POST /api/nutrition-plans, ADR 0014). La fecha de inicio no está en
 * ningún Excel: la pone el usuario al confirmar.
 *
 * CAPA: Infraestructura (MealPlanning\Infrastructure\Http), adaptador de entrada.
 */
final class ConfirmPlanImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'starts_on' => ['required', 'date_format:Y-m-d'],
        ];
    }

    public function startsOn(): DateTimeImmutable
    {
        $startsOn = $this->validated('starts_on');

        // El «!» del formato pone la hora a medianoche: el dominio trabaja con
        // fechas del calendario, no con instantes (HEXM-0015).
        return (is_string($startsOn) ? DateTimeImmutable::createFromFormat('!Y-m-d', $startsOn) : false)
            ?: throw new LogicException('date_format:Y-m-d ya ha validado la fecha.');
    }
}
