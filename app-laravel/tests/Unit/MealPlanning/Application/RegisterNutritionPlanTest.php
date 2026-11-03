<?php

/*
| Tests del caso de uso RegisterNutritionPlan (alta de un plan).
|
| Sin Laravel ni PostgreSQL: el repositorio en memoria, que cumple el mismo
| contrato que el de Eloquent (HEXM-0020). Comprueban que:
|   - los datos en el formato de los documentos se traducen al agregado
|     (el menú 6 es el domingo, el día 10 es el miércoles…);
|   - las reglas las sigue vigilando el dominio, con sus mensajes;
|   - lo que solo existe al traducir (un tipo de comida o un menú repetido)
|     también se rechaza en español;
|   - si algo falla, no se guarda nada.
*/

use MealPlanning\Application\RegisterNutritionPlan;
use MealPlanning\Application\RegisterNutritionPlanCommand;
use MealPlanning\Domain\DuplicatedPlanStart;
use MealPlanning\Domain\ExchangeCategory;
use MealPlanning\Domain\InvalidExchangeTable;
use MealPlanning\Domain\InvalidFixedMeal;
use MealPlanning\Domain\InvalidMenuDay;
use MealPlanning\Domain\InvalidMenuWeek;
use MealPlanning\Domain\InvalidNutritionPlan;
use MealPlanning\Domain\InvalidOptionGroup;
use MealPlanning\Domain\MealType;
use MealPlanning\Domain\NutritionPlan;
use MealPlanning\Domain\Weekday;
use MealPlanning\Domain\WeekSource;
use MealPlanning\Infrastructure\InMemoryNutritionPlanRepository;
use Shared\Domain\DomainException;
use Tests\Support\MealPlanning\NutritionPlanMother;

/**
 * Registra el comando y devuelve el plan guardado.
 */
function registerAndFind(RegisterNutritionPlanCommand $command, ?InMemoryNutritionPlanRepository $plans = null): NutritionPlan
{
    $plans ??= new InMemoryNutritionPlanRepository;
    $id = (new RegisterNutritionPlan($plans))($command);

    return $plans->find($id) ?? throw new LogicException('El plan no se ha guardado.');
}

it('registers a plan and returns the id of the saved plan', function () {
    $plan = registerAndFind(NutritionPlanMother::registerCommand());

    expect($plan->number()->value())->toBe(27)
        ->and($plan->startsOn()->format('Y-m-d'))->toBe('2026-11-02');
});

it('translates the nutritionist menus 1 to 6 into weekdays, skipping Saturday', function () {
    $week = registerAndFind(NutritionPlanMother::registerCommand())->week(1);

    // El menú 6 es el domingo: la regla está en el dominio, no aquí.
    expect($week->source())->toBe(WeekSource::Nutritionist)
        ->and($week->day(Weekday::Monday)?->lunch()->description())->toBe('Comida del menú 1')
        ->and($week->day(Weekday::Sunday)?->dinner()->description())->toBe('Cena del menú 6')
        ->and($week->day(Weekday::Saturday))->toBeNull();
});

it('translates the self days 8 to 14 into weekdays, with day 13 empty', function () {
    $week = registerAndFind(NutritionPlanMother::registerCommand())->week(2);
    $wednesday = $week->day(Weekday::Wednesday);

    expect($week->source())->toBe(WeekSource::Self)
        ->and($wednesday?->lunch()->name())->toBe('Comida del día 10')
        ->and($wednesday?->lunch()->ingredients()[0]->quantity())->toBe('80 g')
        ->and($week->day(Weekday::Saturday))->toBeNull();
});

it('accepts the free day 13 missing altogether', function () {
    // El documento a veces ni siquiera incluye el día 13.
    $days = array_values(array_filter(NutritionPlanMother::registerCommand()->selfWeek, fn (array $day) => $day['day'] !== 13));

    expect(registerAndFind(NutritionPlanMother::registerCommand(['selfWeek' => $days]))->week(2)->days())->toHaveCount(6);
});

it('keeps the fixed meals and the exchange table', function () {
    $plan = registerAndFind(NutritionPlanMother::registerCommand());

    expect($plan->fixedMeal(MealType::Breakfast)?->optionGroups()[0]->count())->toBe(1)
        ->and($plan->fixedMeal(MealType::AfternoonSnack)?->description())->toBe('Infusión y 20 g de nueces')
        ->and($plan->exchangeTable()->exchangesIn(ExchangeCategory::Protein)[0]->food())->toBe('Pechuga de pollo')
        ->and($plan->exchangeTable()->rules())->toBe(['La grasa solo en la comida o en la cena, no en ambas.']);
});

it('leaves the business rules to the domain, with its messages', function (array $changes, string $exception, string $message) {
    expect(fn () => registerAndFind(NutritionPlanMother::registerCommand($changes)))->toThrow($exception, $message);
})->with([
    'starts on a Tuesday' => [
        ['startsOn' => new DateTimeImmutable('2026-11-03')],
        InvalidNutritionPlan::class,
        'Un plan empieza siempre en lunes, y el 03/11/2026 es martes.',
    ],
    'a week with five menus' => [
        ['nutritionistWeek' => array_slice(NutritionPlanMother::registerCommand()->nutritionistWeek, 0, 5)],
        InvalidMenuWeek::class,
        'Una semana debe tener 6 días con menú (de lunes a viernes y el domingo), y esta tiene 5.',
    ],
    'a menu without lunch' => [
        ['nutritionistWeek' => [['menu' => 1, 'lunch' => null, 'dinner' => 'Cena'], ...array_slice(NutritionPlanMother::registerCommand()->nutritionistWeek, 1)]],
        InvalidMenuDay::class,
        'Al menú del lunes le falta la comida.',
    ],
    'a menu on Saturday (day 13)' => [
        ['selfWeek' => [...array_slice(NutritionPlanMother::registerCommand()->selfWeek, 0, 5), ['day' => 13, 'lunch' => ['name' => 'Pizza', 'ingredients' => []], 'dinner' => null], NutritionPlanMother::registerCommand()->selfWeek[6]]],
        InvalidMenuDay::class,
        'sábado',
    ],
    'a menu number out of range' => [
        ['nutritionistWeek' => [...array_slice(NutritionPlanMother::registerCommand()->nutritionistWeek, 0, 5), ['menu' => 7, 'lunch' => 'A', 'dinner' => 'B']]],
        InvalidMenuWeek::class,
        'La semana 1 numera los menús del 1 al 6: el menú 7 no existe.',
    ],
    'choose more options than there are' => [
        ['fixedMeals' => [['meal_type' => 'breakfast', 'description' => null, 'option_groups' => [['choose' => 3, 'options' => ['Tostada', 'Avena']]]]]],
        InvalidOptionGroup::class,
        '3',
    ],
]);

it('rejects what only exists when translating, in Spanish', function (array $changes, string $exception, string $message) {
    expect(fn () => registerAndFind(NutritionPlanMother::registerCommand($changes)))->toThrow($exception, $message);
})->with([
    'an unknown meal type' => [
        ['fixedMeals' => [['meal_type' => 'brunch', 'description' => 'Tortitas', 'option_groups' => []]]],
        InvalidFixedMeal::class,
        '"brunch" no es un tipo de comida',
    ],
    'an unknown exchange category' => [
        ['exchanges' => [['category' => 'sweets', 'food' => 'Chocolate', 'serving' => '10 g']]],
        InvalidExchangeTable::class,
        '"sweets" no es una categoría de intercambio',
    ],
    'a repeated nutritionist menu' => [
        ['nutritionistWeek' => [...NutritionPlanMother::registerCommand()->nutritionistWeek, ['menu' => 2, 'lunch' => 'Otra', 'dinner' => 'Otra']]],
        InvalidMenuWeek::class,
        'El menú 2 aparece más de una vez en la semana 1.',
    ],
    'a repeated self day' => [
        ['selfWeek' => [...NutritionPlanMother::registerCommand()->selfWeek, ['day' => 9, 'lunch' => ['name' => 'Otra', 'ingredients' => []], 'dinner' => ['name' => 'Otra', 'ingredients' => []]]]],
        InvalidMenuWeek::class,
        'El día 9 aparece más de una vez en la semana 2.',
    ],
]);

it('reports every rejection as a domain error (HTTP 422)', function () {
    expect(InvalidFixedMeal::unknownMealType('brunch'))->toBeInstanceOf(DomainException::class)
        ->and(InvalidMenuWeek::duplicatedSelfDay(9))->toBeInstanceOf(DomainException::class);
});

it('saves nothing when the plan is not valid', function () {
    // Todo o nada: la excepción salta al construir el agregado, antes de save().
    $plans = new InMemoryNutritionPlanRepository;

    try {
        registerAndFind(NutritionPlanMother::registerCommand(['startsOn' => new DateTimeImmutable('2026-11-03')]), $plans);
    } catch (InvalidNutritionPlan) {
        // Esperado.
    }

    expect($plans->inForceOn(new DateTimeImmutable('2026-12-01')))->toBeNull();
});

it('rejects a plan that starts on the same day as another one', function () {
    // La regla entre planes la sigue respondiendo el repositorio (ADR 0011).
    $plans = new InMemoryNutritionPlanRepository;
    registerAndFind(NutritionPlanMother::registerCommand(), $plans);

    expect(fn () => registerAndFind(NutritionPlanMother::registerCommand(['number' => 28]), $plans))
        ->toThrow(DuplicatedPlanStart::class, 'El plan 28 no puede empezar el 02/11/2026: ese día ya empieza el plan 27.');
});
