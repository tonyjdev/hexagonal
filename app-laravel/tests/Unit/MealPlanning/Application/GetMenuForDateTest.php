<?php

/*
| Tests del caso de uso GetMenuForDate ("¿qué me toca comer hoy?").
|
| El núcleo se prueba sin Laravel: el repositorio en memoria, el MealCalendar
| real (es puro, no hace falta sustituirlo) y un FrozenClock que fija "hoy".
| Ningún test depende del día en que se ejecuta.
|
| Plan 27 (NutritionPlanMother), inicio el lunes 02/11/2026:
|   02/11 … 08/11 → semana 1 · 09/11 … 15/11 → semana 2 · 16/11 → semana 1…
*/

use MealPlanning\Application\DishIngredientView;
use MealPlanning\Application\DishView;
use MealPlanning\Application\GetMenuForDate;
use MealPlanning\Application\NoPlanInForce;
use MealPlanning\Application\ScheduledDayView;
use MealPlanning\Domain\MealCalendar;
use MealPlanning\Infrastructure\InMemoryNutritionPlanRepository;
use Shared\Application\Clock;
use Shared\Application\NotFound;
use Shared\Domain\DomainException;
use Shared\Infrastructure\FrozenClock;
use Tests\Support\MealPlanning\NutritionPlanMother;

/**
 * El caso de uso con el plan 27 guardado y el reloj parado en $today.
 */
function getMenuForDate(Clock $clock, bool $withPlan27 = true): GetMenuForDate
{
    $plans = new InMemoryNutritionPlanRepository;

    if ($withPlan27) {
        $plans->save(NutritionPlanMother::plan(27, '2026-11-02'));
    }

    return new GetMenuForDate($plans, new MealCalendar, $clock);
}

/**
 * Un reloj que falla si alguien lo consulta: demuestra que no se usa.
 */
function clockThatMustNotBeAsked(): Clock
{
    return new class implements Clock
    {
        public function today(): DateTimeImmutable
        {
            throw new LogicException('No se debería consultar el reloj.');
        }
    };
}

it('tells what is on the menu today, according to the clock', function () {
    // Criterio de aceptación: miércoles 11/11 → semana 2, miércoles (día 10).
    $view = getMenuForDate(FrozenClock::on('2026-11-11'))();

    expect($view)->toEqual(new ScheduledDayView(
        date: '2026-11-11',
        planNumber: 27,
        weekNumber: 2,
        weekdayNumber: 3,
        weekday: 'miércoles',
        isFreeDay: false,
        lunch: new DishView('Comida del día 10', null, [new DishIngredientView('Pasta integral', '80 g')]),
        dinner: new DishView('Cena del día 10', null, []),
    ));
});

it('shows the nutritionist week dishes as free text', function () {
    // Semana 1: el plato es una descripción, sin nombre corto ni ingredientes.
    $view = getMenuForDate(FrozenClock::on('2026-11-03'))();

    expect($view->weekNumber)->toBe(1)
        ->and($view->weekday)->toBe('martes')
        ->and($view->lunch)->toEqual(new DishView(null, 'Comida del menú 2', []))
        ->and($view->dinner)->toEqual(new DishView(null, 'Cena del menú 2', []));
});

it('ignores the clock when a date is given', function () {
    // "¿Qué me toca el día X?": la fecha explícita manda y el reloj ni se
    // consulta (si se consultara, este reloj lanzaría una excepción).
    $view = getMenuForDate(clockThatMustNotBeAsked())(new DateTimeImmutable('2026-11-16'));

    // 16/11: dos semanas completas después del inicio → otra vez la semana 1.
    expect($view->date)->toBe('2026-11-16')
        ->and($view->weekNumber)->toBe(1)
        ->and($view->weekday)->toBe('lunes')
        ->and($view->lunch?->description)->toBe('Comida del menú 1');
});

it('reports Saturday as a free day without dishes', function () {
    $view = getMenuForDate(FrozenClock::on('2026-11-14'))();

    expect($view->isFreeDay)->toBeTrue()
        ->and($view->weekday)->toBe('sábado')
        ->and($view->weekNumber)->toBe(2)
        ->and($view->planNumber)->toBe(27)
        ->and($view->lunch)->toBeNull()
        ->and($view->dinner)->toBeNull();
});

it('uses the plan in force on that date', function () {
    // Con dos planes, el 28 cierra la vigencia del 27 (regla 5).
    $plans = new InMemoryNutritionPlanRepository;
    $plans->save(NutritionPlanMother::plan(27, '2026-11-02'));
    $plans->save(NutritionPlanMother::plan(28, '2027-01-04'));
    $getMenu = new GetMenuForDate($plans, new MealCalendar, clockThatMustNotBeAsked());

    expect($getMenu(new DateTimeImmutable('2027-01-03'))->planNumber)->toBe(27)
        // El 04/01 empieza el 28 y, con él, su semana 1.
        ->and($getMenu(new DateTimeImmutable('2027-01-04'))->planNumber)->toBe(28)
        ->and($getMenu(new DateTimeImmutable('2027-01-04'))->weekNumber)->toBe(1);
});

it('reports a date without a plan in force as not found, in Spanish', function () {
    // El día antes de que empiece el primer plan.
    expect(fn () => getMenuForDate(FrozenClock::on('2026-11-01'))())
        ->toThrow(NoPlanInForce::class, 'No hay ningún plan nutricional vigente el 01/11/2026.');
});

it('reports no plan in force when there are no plans at all', function () {
    expect(fn () => getMenuForDate(FrozenClock::on('2026-11-11'), withPlan27: false)())
        ->toThrow(NoPlanInForce::class);
});

it('treats "no plan in force" as a query result, not as a broken business rule', function () {
    // Como RecipeNotFound: un NotFound (→ 404), no una DomainException (→ 422).
    expect(NoPlanInForce::on(new DateTimeImmutable('2026-11-01')))
        ->toBeInstanceOf(NotFound::class)
        ->not->toBeInstanceOf(DomainException::class);
});

it('does not change anything: asking twice gives the same answer', function () {
    // CQS: una consulta se puede repetir sin efectos.
    $getMenu = getMenuForDate(FrozenClock::on('2026-11-11'));

    expect($getMenu())->toEqual($getMenu());
});
