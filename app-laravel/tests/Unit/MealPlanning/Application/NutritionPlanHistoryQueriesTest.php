<?php

/*
| Tests de las consultas del histórico: ListNutritionPlans y
| GetNutritionPlan.
|
| Sin Laravel: el repositorio en memoria y un FrozenClock que fija "hoy".
| Planes 26 (inicio 07/09/2026) y 27 (inicio 02/11/2026).
*/

use MealPlanning\Application\DishView;
use MealPlanning\Application\GetNutritionPlan;
use MealPlanning\Application\ListNutritionPlans;
use MealPlanning\Application\NutritionPlanSummaryView;
use MealPlanning\Application\NutritionPlanView;
use MealPlanning\Application\PlanNotFound;
use MealPlanning\Domain\InvalidNutritionPlanId;
use MealPlanning\Domain\NutritionPlan;
use MealPlanning\Domain\NutritionPlanId;
use MealPlanning\Infrastructure\InMemoryNutritionPlanRepository;
use Shared\Application\NotFound;
use Shared\Infrastructure\FrozenClock;
use Tests\Support\MealPlanning\NutritionPlanMother;

/**
 * Un repositorio con los planes dados.
 */
function plansRepository(NutritionPlan ...$plans): InMemoryNutritionPlanRepository
{
    $repository = new InMemoryNutritionPlanRepository;

    foreach ($plans as $plan) {
        $repository->save($plan);
    }

    return $repository;
}

// --- ListNutritionPlans -------------------------------------------------------

it('lists the history with the validity of each plan', function () {
    // Criterio de aceptación: del más reciente al más antiguo; el 27 vigente y
    // el 26 del 07/09 al 01/11.
    $p26 = NutritionPlanMother::plan(26, '2026-09-07');
    $p27 = NutritionPlanMother::plan(27, '2026-11-02');
    $list = new ListNutritionPlans(plansRepository($p26, $p27), FrozenClock::on('2026-11-11'));

    expect($list())->toEqual([
        new NutritionPlanSummaryView($p27->id()->value(), 27, '2026-11-02', null, 'in_force', 'vigente'),
        new NutritionPlanSummaryView($p26->id()->value(), 26, '2026-09-07', '2026-11-01', 'finished', 'finalizado'),
    ]);
});

it('derives the status from today, not from anything stored', function () {
    // El mismo histórico, preguntado en tres días distintos.
    $repository = plansRepository(NutritionPlanMother::plan(26, '2026-09-07'), NutritionPlanMother::plan(27, '2026-11-02'));
    $statusOn = fn (string $today): array => array_map(
        fn (NutritionPlanSummaryView $plan): string => "{$plan->number}:{$plan->status}",
        (new ListNutritionPlans($repository, FrozenClock::on($today)))(),
    );

    // El último día del 26, el 26 sigue vigente y el 27 es "próximo".
    expect($statusOn('2026-11-01'))->toBe(['27:upcoming', '26:in_force'])
        ->and($statusOn('2026-11-02'))->toBe(['27:in_force', '26:finished'])
        // Antes de los dos: ambos próximos.
        ->and($statusOn('2026-08-01'))->toBe(['27:upcoming', '26:upcoming']);
});

it('lists nothing when there are no plans', function () {
    expect((new ListNutritionPlans(new InMemoryNutritionPlanRepository, FrozenClock::on('2026-11-11')))())->toBe([]);
});

// --- GetNutritionPlan ---------------------------------------------------------

it('returns the whole plan as a read view, not the aggregate', function () {
    $plan = NutritionPlanMother::plan(27, '2026-11-02');

    $view = (new GetNutritionPlan(plansRepository($plan)))($plan->id()->value());

    expect($view)->toBeInstanceOf(NutritionPlanView::class)
        ->and($view->number)->toBe(27)
        ->and($view->startsOn)->toBe('2026-11-02')
        ->and($view->fixedMeals[0])->toBe([
            'meal_type' => 'breakfast',
            'meal_type_label' => 'desayuno',
            'description' => 'Café con leche desnatada',
            'option_groups' => [['choose' => 1, 'options' => ['Tostada de pan integral (40 g) con tomate', '30 g de copos de avena']]],
        ])
        ->and($view->exchanges[1])->toBe(['category' => 'protein', 'category_label' => 'proteínas', 'food' => 'Pechuga de pollo', 'serving' => '100 g'])
        ->and($view->exchangeRules)->toBe(['La grasa solo en la comida o en la cena, no en ambas.'])
        ->and($view->weeks)->toHaveCount(2)
        ->and($view->weeks[1]['source_label'])->toBe('elaboración propia')
        ->and($view->weeks[1]['days'])->toHaveCount(6)
        ->and($view->weeks[1]['days'][2]['weekday'])->toBe('miércoles')
        ->and($view->weeks[1]['days'][2]['lunch'])->toBeInstanceOf(DishView::class)
        ->and($view->weeks[1]['days'][2]['lunch']->name)->toBe('Comida del día 10')
        ->and($view->weeks[0]['days'][5]['lunch']->description)->toBe('Comida del menú 6');
});

it('reports a missing plan as not found, in Spanish', function () {
    $id = NutritionPlanId::generate();

    expect(fn () => (new GetNutritionPlan(new InMemoryNutritionPlanRepository))($id->value()))
        ->toThrow(PlanNotFound::class, "No existe ningún plan nutricional con el identificador «{$id->value()}».");

    expect(PlanNotFound::withId($id))->toBeInstanceOf(NotFound::class);
});

it('rejects a malformed id as a domain error', function () {
    expect(fn () => (new GetNutritionPlan(new InMemoryNutritionPlanRepository))('abc'))
        ->toThrow(InvalidNutritionPlanId::class, '"abc" no es un identificador de plan válido.');
});
