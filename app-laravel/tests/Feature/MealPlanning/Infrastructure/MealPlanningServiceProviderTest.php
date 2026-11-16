<?php

/*
| Tests del cableado de MealPlanning (MealPlanningServiceProvider).
|
| CONCEPTO: probar la raíz de composición (como RecipesServiceProviderTest)
| Los tests unitarios de RegisterNutritionPlan y GetMenuForDate construyen
| los casos de uso a mano con el adaptador en memoria. Estos comprueban lo
| que aquellos no ven: que en la aplicación real el puerto está enchufado al
| adaptador de Eloquent.
*/

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use MealPlanning\Application\ConfirmPlanImport;
use MealPlanning\Application\RegisterNutritionPlan;
use MealPlanning\Domain\NutritionPlanRepository;
use MealPlanning\Domain\PlanImportRepository;
use MealPlanning\Infrastructure\Persistence\Eloquent\EloquentNutritionPlanRepository;
use MealPlanning\Infrastructure\Persistence\Eloquent\EloquentPlanImportRepository;
use Tests\Support\MealPlanning\NutritionPlanMother;
use Tests\Support\MealPlanning\PlanImportMother;

uses(RefreshDatabase::class);

it('binds the nutrition plan repository port to the Eloquent adapter', function () {
    expect(app(NutritionPlanRepository::class))->toBeInstanceOf(EloquentNutritionPlanRepository::class);
});

it('binds the plan import repository port to the Eloquent adapter', function () {
    expect(app(PlanImportRepository::class))->toBeInstanceOf(EloquentPlanImportRepository::class);
});

it('builds the ConfirmPlanImport use case with the Eloquent adapters', function () {
    // Autowiring en dos niveles: ConfirmPlanImport pide un
    // PlanImportRepository y un RegisterNutritionPlan, que a su vez pide sus
    // propios puertos. Se comprueba por su efecto: el plan acaba en
    // PostgreSQL y la importación, confirmada.
    $import = PlanImportMother::readyToConfirm();
    app(PlanImportRepository::class)->save($import);

    $planId = app(ConfirmPlanImport::class)($import->id()->value(), new DateTimeImmutable('2026-11-02'));

    expect(DB::table('nutrition_plans')->where('id', $planId->value())->count())->toBe(1)
        ->and(DB::table('plan_imports')->where('id', $import->id()->value())->value('status'))->toBe('confirmed');
});

it('builds the RegisterNutritionPlan use case with the Eloquent adapter', function () {
    // Autowiring: nadie ha registrado RegisterNutritionPlan; el contenedor
    // lee su constructor y usa el binding del puerto. Se comprueba por su
    // efecto: el plan acaba en PostgreSQL.
    $id = app(RegisterNutritionPlan::class)(NutritionPlanMother::registerCommand());

    expect(DB::table('nutrition_plans')->where('id', $id->value())->count())->toBe(1);
});
