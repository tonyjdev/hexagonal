<?php

/*
| Tests de PlanNumber: el número de plan de la nutricionista es positivo.
*/

use MealPlanning\Domain\InvalidPlanNumber;
use MealPlanning\Domain\PlanNumber;

it('keeps the plan number', function () {
    expect(PlanNumber::of(27)->value())->toBe(27);
});

it('cannot be zero or negative', function (int $value) {
    PlanNumber::of($value);
})->with([0, -1])->throws(InvalidPlanNumber::class, 'El número de plan debe ser mayor que cero');

it('is compared by value', function () {
    expect(PlanNumber::of(27)->equals(PlanNumber::of(27)))->toBeTrue()
        ->and(PlanNumber::of(27)->equals(PlanNumber::of(28)))->toBeFalse();
});
