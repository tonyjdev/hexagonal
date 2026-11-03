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
use MealPlanning\Application\RegisterNutritionPlan;
use MealPlanning\Domain\NutritionPlanRepository;
use MealPlanning\Infrastructure\Persistence\Eloquent\EloquentNutritionPlanRepository;
use Tests\Support\MealPlanning\NutritionPlanMother;

uses(RefreshDatabase::class);

it('binds the nutrition plan repository port to the Eloquent adapter', function () {
    expect(app(NutritionPlanRepository::class))->toBeInstanceOf(EloquentNutritionPlanRepository::class);
});

it('builds the RegisterNutritionPlan use case with the Eloquent adapter', function () {
    // Autowiring: nadie ha registrado RegisterNutritionPlan; el contenedor
    // lee su constructor y usa el binding del puerto. Se comprueba por su
    // efecto: el plan acaba en PostgreSQL.
    $id = app(RegisterNutritionPlan::class)(NutritionPlanMother::registerCommand());

    expect(DB::table('nutrition_plans')->where('id', $id->value())->count())->toBe(1);
});
