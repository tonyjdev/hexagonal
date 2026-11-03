<?php

declare(strict_types=1);

namespace MealPlanning\Infrastructure\Http;

use MealPlanning\Application\GetMenuForDate;

/**
 * GetMenuForDateController: GET /api/menu, "¿qué me toca comer hoy (o el día
 * X)?" por HTTP.
 *
 * CONCEPTO: adaptador driving sobre un caso de uso que ya existía
 * GetMenuForDate (HEXM-0019) se escribió y se probó sin HTTP. Este
 * controlador lo publica sin tocar ni una línea del núcleo: petición →
 * fecha (o null) → caso de uso → ScheduledDayView → JSON. Sin plan vigente,
 * NoPlanInForce (un NotFound) llega como 404 por el manejador global.
 *
 * CAPA: Infraestructura (MealPlanning\Infrastructure\Http).
 */
final readonly class GetMenuForDateController
{
    public function __construct(private GetMenuForDate $getMenuForDate) {}

    public function __invoke(GetMenuForDateRequest $request): ScheduledDayResource
    {
        return new ScheduledDayResource(($this->getMenuForDate)($request->requestedDate()));
    }
}
