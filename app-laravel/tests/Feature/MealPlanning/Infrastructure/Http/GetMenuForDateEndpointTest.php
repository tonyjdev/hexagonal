<?php

/*
| Tests de GET /api/menu: "¿qué me toca comer hoy (o el día X)?" de punta a
| punta (rutas → Form Request → controlador → GetMenuForDate →
| EloquentNutritionPlanRepository → PostgreSQL → ScheduledDayResource).
|
| CONCEPTO: sustituir un puerto en el contenedor durante un test
| En un Laravel clásico, "hoy" se controla con $this->travelTo() o
| Carbon::setTestNow(), que cambian un reloj GLOBAL. Aquí "hoy" es un puerto
| (Shared\Application\Clock), y cada test pone en el contenedor el reloj que
| quiere: $this->app->instance(Clock::class, FrozenClock::on('2026-11-11')).
| El caso de uso lo recibe por su constructor, como en producción recibe
| SystemClock. Nada global que restaurar.
|
| Plan 27 (NutritionPlanMother), inicio el lunes 02/11/2026.
*/

use Illuminate\Foundation\Testing\RefreshDatabase;
use MealPlanning\Domain\NutritionPlanRepository;
use Shared\Application\Clock;
use Shared\Infrastructure\FrozenClock;
use Tests\Support\MealPlanning\NutritionPlanMother;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(NutritionPlanRepository::class)->save(NutritionPlanMother::plan(27, '2026-11-02'));
});

it('tells what is on the menu today, according to the clock', function () {
    // Criterio de aceptación: con el reloj en el miércoles 11/11, la semana 2.
    $this->app->instance(Clock::class, FrozenClock::on('2026-11-11'));

    $this->getJson('/api/menu')
        ->assertOk()
        ->assertExactJson(['data' => [
            'date' => '2026-11-11',
            'plan_number' => 27,
            'week_number' => 2,
            'weekday_number' => 3,
            'weekday' => 'miércoles',
            'is_free_day' => false,
            'lunch' => [
                'name' => 'Comida del día 10',
                'description' => null,
                'ingredients' => [['name' => 'Pasta integral', 'quantity' => '80 g']],
            ],
            'dinner' => ['name' => 'Cena del día 10', 'description' => null, 'ingredients' => []],
        ]]);
});

it('shows a nutritionist dish as free text', function () {
    $this->app->instance(Clock::class, FrozenClock::on('2026-11-03'));

    $this->getJson('/api/menu')
        ->assertOk()
        ->assertJsonPath('data.week_number', 1)
        ->assertJsonPath('data.weekday', 'martes')
        ->assertJsonPath('data.lunch', ['name' => null, 'description' => 'Comida del menú 2', 'ingredients' => []]);
});

it('answers for the requested date without asking the clock', function () {
    // Un reloj que falla si alguien lo consulta: con ?date, no se usa.
    $this->app->instance(Clock::class, new class implements Clock
    {
        public function today(): DateTimeImmutable
        {
            throw new LogicException('No se debería consultar el reloj.');
        }
    });

    // Criterio de aceptación: el sábado 14/11 es el día libre, sin platos.
    $this->getJson('/api/menu?date=2026-11-14')
        ->assertOk()
        ->assertJsonPath('data.date', '2026-11-14')
        ->assertJsonPath('data.is_free_day', true)
        ->assertJsonPath('data.weekday', 'sábado')
        ->assertJsonPath('data.week_number', 2)
        ->assertJsonPath('data.lunch', null)
        ->assertJsonPath('data.dinner', null);
});

it('reports a date without a plan in force as not found', function () {
    // El domingo antes del plan 27: NoPlanInForce → 404 por el manejador
    // global de NotFound, sin código nuevo.
    $this->getJson('/api/menu?date=2026-11-01')
        ->assertNotFound()
        ->assertExactJson(['message' => 'No hay ningún plan nutricional vigente el 01/11/2026.']);
});

it('rejects a malformed date before reaching the use case', function (string $date) {
    $this->getJson('/api/menu?date='.$date)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['date']);
})->with(['abc', '2026-13-40', '11/11/2026', '2026-11-11T10:00']);
