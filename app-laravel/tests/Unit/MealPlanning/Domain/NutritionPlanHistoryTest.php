<?php

/*
| Tests de la vigencia de los planes en el tiempo: ValidityPeriod y
| NutritionPlanHistory (regla 19, ADR 0010).
|
| Planes de ejemplo: el 25 empieza el 06/07/2026, el 26 el 07/09/2026 y el
| 27 el 02/11/2026.
*/

use MealPlanning\Domain\NutritionPlan;
use MealPlanning\Domain\NutritionPlanHistory;
use MealPlanning\Domain\ValidityPeriod;
use Tests\Support\MealPlanning\NutritionPlanMother;

it('orders the plans from the most recent to the oldest, whatever the input order', function () {
    $history = NutritionPlanHistory::of([
        NutritionPlanMother::plan(26, '2026-09-07'),
        NutritionPlanMother::plan(27, '2026-11-02'),
        NutritionPlanMother::plan(25, '2026-07-06'),
    ]);

    expect(array_map(fn (NutritionPlan $plan) => $plan->number()->value(), $history->plans()))->toBe([27, 26, 25]);
});

it('derives each period from the start of the next plan', function () {
    // El fin no se guarda: el 26 acaba el día antes de que empiece el 27, y
    // el más reciente no tiene fin.
    $p25 = NutritionPlanMother::plan(25, '2026-07-06');
    $p26 = NutritionPlanMother::plan(26, '2026-09-07');
    $p27 = NutritionPlanMother::plan(27, '2026-11-02');
    $history = NutritionPlanHistory::of([$p25, $p26, $p27]);

    expect($history->periodOf($p27)->startsOn()->format('Y-m-d'))->toBe('2026-11-02')
        ->and($history->periodOf($p27)->endsOn())->toBeNull()
        ->and($history->periodOf($p26)->endsOn()?->format('Y-m-d'))->toBe('2026-11-01')
        ->and($history->periodOf($p25)->endsOn()?->format('Y-m-d'))->toBe('2026-09-06');
});

it('ends a period on the day before the next start, also across the change of hour', function () {
    // El cambio de hora de marzo de 2027 (28/03) no mueve el último día.
    $old = NutritionPlanMother::plan(30, '2027-03-01');
    $new = NutritionPlanMother::plan(31, '2027-03-29');

    expect(NutritionPlanHistory::of([$old, $new])->periodOf($old)->endsOn()?->format('Y-m-d'))->toBe('2027-03-28');
});

it('has an empty history without plans', function () {
    expect(NutritionPlanHistory::of([])->plans())->toBe([]);
});

it('refuses the period of a plan that is not in the history', function () {
    NutritionPlanHistory::of([NutritionPlanMother::plan(27)])->periodOf(NutritionPlanMother::plan(28, '2027-01-04'));
})->throws(LogicException::class);

it('tells whether a closed period includes a date, both ends included', function () {
    $period = ValidityPeriod::between(new DateTimeImmutable('2026-09-07'), new DateTimeImmutable('2026-11-01'));

    expect($period->includes(new DateTimeImmutable('2026-09-06')))->toBeFalse()
        ->and($period->includes(new DateTimeImmutable('2026-09-07')))->toBeTrue()
        ->and($period->includes(new DateTimeImmutable('2026-11-01 23:59')))->toBeTrue()
        ->and($period->includes(new DateTimeImmutable('2026-11-02')))->toBeFalse()
        ->and($period->hasEndedBefore(new DateTimeImmutable('2026-11-02')))->toBeTrue()
        ->and($period->hasEndedBefore(new DateTimeImmutable('2026-11-01')))->toBeFalse()
        ->and($period->startsAfter(new DateTimeImmutable('2026-09-06')))->toBeTrue();
});

it('never ends an open period', function () {
    $period = ValidityPeriod::from(new DateTimeImmutable('2026-11-02'));

    expect($period->endsOn())->toBeNull()
        ->and($period->includes(new DateTimeImmutable('2030-01-01')))->toBeTrue()
        ->and($period->hasEndedBefore(new DateTimeImmutable('2030-01-01')))->toBeFalse();
});
