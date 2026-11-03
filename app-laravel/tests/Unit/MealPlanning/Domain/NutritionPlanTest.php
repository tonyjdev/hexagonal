<?php

/*
| Tests del agregado NutritionPlan: un plan completo (inspirado en el plan 27,
| con datos ficticios), sus invariantes (las dos semanas, el lunes de inicio,
| sin pautas fijas repetidas) y que desde fuera no se puede cambiar nada.
|
| Son tests unitarios puros: sin Laravel, sin base de datos.
|
| Ojo con los nombres de las funciones auxiliares: en Pest son GLOBALES y se
| comparten entre todos los ficheros de test. Por eso llevan el prefijo plan27
| (menuDay() o selfDays() ya existen en MenuWeekTest).
*/

use MealPlanning\Domain\Dish;
use MealPlanning\Domain\DishIngredient;
use MealPlanning\Domain\Exchange;
use MealPlanning\Domain\ExchangeCategory;
use MealPlanning\Domain\ExchangeTable;
use MealPlanning\Domain\FixedMeal;
use MealPlanning\Domain\InvalidMenuWeek;
use MealPlanning\Domain\InvalidNutritionPlan;
use MealPlanning\Domain\MealOption;
use MealPlanning\Domain\MealType;
use MealPlanning\Domain\MenuWeek;
use MealPlanning\Domain\NutritionPlan;
use MealPlanning\Domain\NutritionPlanId;
use MealPlanning\Domain\OptionGroup;
use MealPlanning\Domain\PlanNumber;
use MealPlanning\Domain\Weekday;
use MealPlanning\Domain\WeekSource;

// --- Datos de ejemplo: un "plan 27" ficticio ----------------------------------

/**
 * Desayuno, media mañana y media tarde.
 *
 * @return list<FixedMeal>
 */
function plan27FixedMeals(): array
{
    return [
        FixedMeal::of(MealType::Breakfast, 'Café con leche desnatada', [
            OptionGroup::choose(1, [
                MealOption::of('Tostada de pan integral (40 g) con tomate'),
                MealOption::of('30 g de copos de avena'),
            ]),
        ]),
        FixedMeal::of(MealType::MidMorning, null, [
            OptionGroup::choose(1, [MealOption::of('1 pieza de fruta'), MealOption::of('Yogur natural desnatado')]),
        ]),
        FixedMeal::of(MealType::AfternoonSnack, 'Infusión y 20 g de nueces'),
    ];
}

function plan27ExchangeTable(): ExchangeTable
{
    return ExchangeTable::of([
        Exchange::of(ExchangeCategory::Carbohydrates, 'Arroz', '60 g en crudo'),
        Exchange::of(ExchangeCategory::Protein, 'Pechuga de pollo', '100 g'),
        Exchange::of(ExchangeCategory::Fat, 'Aceite de oliva', '1 cucharada'),
    ], [
        'La legumbre cuenta como ½ intercambio de proteína.',
        'La grasa solo en la comida o en la cena, no en ambas.',
    ]);
}

/**
 * Semana 1: menús 1 a 6 de la nutricionista, en texto libre.
 */
function plan27Week1(): MenuWeek
{
    $menus = [];

    foreach (range(1, 6) as $menu) {
        $menus[$menu] = [
            'lunch' => Dish::described("Lentejas (½ bote) con pisto · menú {$menu}"),
            'dinner' => Dish::described("Merluza a la plancha con ensalada · menú {$menu}"),
        ];
    }

    return MenuWeek::fromNutritionistMenus($menus);
}

/**
 * Semana 2: días 8 a 14 del usuario, con el 13 (sábado) vacío.
 */
function plan27Week2(): MenuWeek
{
    $days = [];

    foreach (range(8, 14) as $day) {
        $days[$day] = $day === 13 ? null : [
            'lunch' => Dish::named("Pasta con salmón y pesto · día {$day}", [
                DishIngredient::of('Pasta integral', '80 g'),
                DishIngredient::of('Salmón', '120 g'),
            ]),
            'dinner' => Dish::named("Tortilla francesa · día {$day}"),
        ];
    }

    return MenuWeek::fromSelfDays($days);
}

/**
 * El plan entero. Cada parámetro permite cambiar una pieza para los tests de
 * las invariantes. El 2 de noviembre de 2026 es lunes.
 *
 * @param  list<FixedMeal>|null  $fixedMeals
 * @param  list<MenuWeek>|null  $weeks
 */
function plan27(
    ?DateTimeImmutable $startsOn = null,
    ?array $fixedMeals = null,
    ?array $weeks = null,
): NutritionPlan {
    return NutritionPlan::create(
        NutritionPlanId::generate(),
        PlanNumber::of(27),
        $startsOn ?? new DateTimeImmutable('2026-11-02'),
        $fixedMeals ?? plan27FixedMeals(),
        plan27ExchangeTable(),
        $weeks ?? [plan27Week1(), plan27Week2()],
    );
}

// --- Un plan completo ---------------------------------------------------------

it('creates a complete plan with its fixed meals, exchanges and two weeks', function () {
    $plan = plan27();

    expect($plan->number()->value())->toBe(27)
        ->and($plan->startsOn()->format('Y-m-d'))->toBe('2026-11-02')
        ->and($plan->fixedMeal(MealType::Breakfast)?->description())->toBe('Café con leche desnatada')
        ->and($plan->exchangeTable()->exchangesIn(ExchangeCategory::Protein)[0]->food())->toBe('Pechuga de pollo')
        ->and($plan->exchangeTable()->rules())->toHaveCount(2)
        ->and($plan->week(1)->source())->toBe(WeekSource::Nutritionist)
        ->and($plan->week(2)->source())->toBe(WeekSource::Self)
        // Leer un día concreto a través del plan: lo que hará el calendario.
        ->and($plan->week(1)->day(Weekday::Sunday)?->lunch()->description())->toBe('Lentejas (½ bote) con pisto · menú 6')
        ->and($plan->week(2)->day(Weekday::Monday)?->lunch()->name())->toBe('Pasta con salmón y pesto · día 8');
});

it('keeps the weeks as 1 and 2, and the fixed meals in the order of the day', function () {
    // Llegan desordenadas; el plan las guarda en su orden canónico.
    $plan = plan27(
        fixedMeals: array_reverse(plan27FixedMeals()),
        weeks: [plan27Week2(), plan27Week1()],
    );

    expect(array_map(fn (MenuWeek $week) => $week->number(), $plan->weeks()))->toBe([1, 2])
        ->and(array_map(fn (FixedMeal $meal) => $meal->mealType(), $plan->fixedMeals()))
        ->toBe([MealType::Breakfast, MealType::MidMorning, MealType::AfternoonSnack]);
});

it('does not require every fixed meal', function () {
    // Si el documento no trae media tarde, el plan es válido sin ella.
    $plan = plan27(fixedMeals: [plan27FixedMeals()[0]]);

    expect($plan->fixedMeals())->toHaveCount(1)
        ->and($plan->fixedMeal(MealType::AfternoonSnack))->toBeNull();
});

it('keeps only the day the plan starts, at midnight', function () {
    $plan = plan27(startsOn: new DateTimeImmutable('2026-11-02 18:30:00'));

    expect($plan->startsOn()->format('Y-m-d H:i:s'))->toBe('2026-11-02 00:00:00');
});

it('is the same plan when it has the same id (entity)', function () {
    $id = NutritionPlanId::generate();
    $make = fn () => NutritionPlan::create(
        $id, PlanNumber::of(27), new DateTimeImmutable('2026-11-02'),
        plan27FixedMeals(), plan27ExchangeTable(), [plan27Week1(), plan27Week2()],
    );

    expect($make()->equals($make()))->toBeTrue()
        ->and($make()->equals(plan27()))->toBeFalse();
});

// --- Invariantes --------------------------------------------------------------

it('rejects a plan without week 2', function () {
    plan27(weeks: [plan27Week1()]);
})->throws(InvalidNutritionPlan::class, 'Un plan tiene dos semanas que se alternan, la 1 y la 2: falta la semana 2.');

it('rejects a plan without weeks', function () {
    plan27(weeks: []);
})->throws(InvalidNutritionPlan::class, 'falta la semana 1.');

it('rejects a plan with a repeated week', function () {
    plan27(weeks: [plan27Week1(), plan27Week1()]);
})->throws(InvalidNutritionPlan::class, 'La semana 1 aparece más de una vez en el plan.');

it('rejects a plan that does not start on Monday', function () {
    // El 4 de noviembre de 2026 es miércoles.
    plan27(startsOn: new DateTimeImmutable('2026-11-04'));
})->throws(InvalidNutritionPlan::class, 'Un plan empieza siempre en lunes, y el 04/11/2026 es miércoles.');

it('rejects a plan with a repeated fixed meal', function () {
    $breakfast = plan27FixedMeals()[0];

    plan27(fixedMeals: [$breakfast, plan27FixedMeals()[1], $breakfast]);
})->throws(InvalidNutritionPlan::class, 'La pauta de desayuno aparece más de una vez en el plan.');

it('only has week 1 and week 2', function () {
    plan27()->week(3);
})->throws(InvalidMenuWeek::class, 'la semana 3 no existe.');

// --- Desde fuera no se puede cambiar nada ------------------------------------

it('cannot be changed through what it returns', function () {
    $plan = plan27();

    // Los arrays que devuelve son COPIAS: tocarlos no afecta al plan.
    $weeks = $plan->weeks();
    $weeks[] = plan27Week1();
    $fixedMeals = $plan->fixedMeals();
    array_pop($fixedMeals);

    // DateTimeImmutable: "cambiarla" devuelve OTRA fecha. PHP 8.5 marca
    // modify() con #[\NoDiscard] y avisa si se tira el resultado (casi
    // siempre es un error: creer que se ha cambiado la fecha). Aquí tirarlo
    // es justo lo que queremos demostrar, y el cast (void) lo deja explícito.
    (void) $plan->startsOn()->modify('+1 day');

    expect($plan->weeks())->toHaveCount(2)
        ->and($plan->fixedMeals())->toHaveCount(3)
        ->and($plan->startsOn()->format('Y-m-d'))->toBe('2026-11-02');
});

it('cannot be changed by assigning its properties', function () {
    $plan = plan27();

    // La clase es readonly: ni desde fuera ni desde dentro se puede
    // reasignar una propiedad. Por Reflection se ve que no hay escapatoria.
    $property = new ReflectionProperty(NutritionPlan::class, 'startsOn');

    expect($property->isReadOnly())->toBeTrue()
        ->and(fn () => $property->setValue($plan, new DateTimeImmutable('2026-11-09')))
        ->toThrow(Error::class);
});
