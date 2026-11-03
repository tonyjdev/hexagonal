<?php

declare(strict_types=1);

namespace MealPlanning\Infrastructure\Http;

use DateTimeImmutable;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * GetMenuForDateRequest: valida la FORMA de GET /api/menu?date=AAAA-MM-DD y
 * convierte la fecha en DateTimeImmutable.
 *
 * CONCEPTO: validación de forma en un adaptador de consulta (ADR 0007)
 * La única pregunta de forma es "¿date es una fecha AAAA-MM-DD?". Que haya un
 * plan vigente ese día no es forma: lo responde el caso de uso (404 con
 * NoPlanInForce). Una fecha mal formada ("abc", "2026-13-40") no llega al
 * caso de uso: 422 de validación, con el error en el campo date.
 *
 * El parámetro es opcional: sin él, GetMenuForDate pregunta por "hoy" al
 * puerto Clock. El adaptador NO calcula hoy: ni siquiera sabe qué zona
 * horaria tiene el usuario.
 *
 * CAPA: Infraestructura (MealPlanning\Infrastructure\Http).
 */
final class GetMenuForDateRequest extends FormRequest
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
            // date_format comprueba también que la fecha exista: 2026-13-40
            // no pasa, aunque tenga la forma de los dígitos.
            'date' => ['sometimes', 'date_format:Y-m-d'],
        ];
    }

    /**
     * La fecha pedida, a medianoche, o null para "hoy".
     *
     * No se llama date(): Illuminate\Http\Request ya tiene un método date()
     * (lee un campo como Carbon) con otra firma, y PHP no deja redefinirlo.
     */
    public function requestedDate(): ?DateTimeImmutable
    {
        /** @var array{date?: string} $data */
        $data = $this->validated();

        if (! isset($data['date'])) {
            return null;
        }

        // '!' pone la hora a cero (sin él, createFromFormat rellenaría la
        // hora actual). La zona es la de PHP: da igual, el calendario solo
        // mira el año-mes-día de la fecha (HEXM-0017).
        return DateTimeImmutable::createFromFormat('!Y-m-d', $data['date'])
            ?: throw new LogicException('date_format:Y-m-d ya ha validado la fecha.');
    }
}
