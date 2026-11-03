<?php

/*
| Tests propios del adaptador EloquentNutritionPlanRepository.
|
| El COMPORTAMIENTO del puerto (guardar, encontrar, vigencia, duplicados) ya
| lo comprueban los tests de contrato con los dos adaptadores. Aquí se
| comprueba lo que solo tiene sentido en PostgreSQL:
|   - cómo queda guardado el plan: una fila con documentos jsonb (ADR 0013);
|   - la red de seguridad de la base de datos (el CHECK de los documentos);
|   - qué pasa al cargar un documento corrupto o que el dominio no admite.
*/

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use MealPlanning\Domain\InvalidMenuWeek;
use MealPlanning\Infrastructure\Persistence\Eloquent\EloquentNutritionPlanRepository;
use Tests\Support\MealPlanning\NutritionPlanMother;

uses(RefreshDatabase::class);

/**
 * Un documento jsonb de la fila de un plan, decodificado para inspeccionarlo.
 *
 * @return array<mixed>
 */
function storedDocument(string $planId, string $column): array
{
    $json = DB::table('nutrition_plans')->where('id', $planId)->value($column);

    return is_string($json) ? (array) json_decode($json, true, flags: JSON_THROW_ON_ERROR) : [];
}

it('stores the whole plan in a single row with JSON documents', function () {
    $plan = NutritionPlanMother::plan(27, '2026-11-02');

    (new EloquentNutritionPlanRepository)->save($plan);

    $row = DB::table('nutrition_plans')->where('id', $plan->id()->value())->first();
    $weeks = storedDocument($plan->id()->value(), 'weeks');

    // Las columnas normales: lo que se consulta (starts_on, como fecha).
    expect(DB::table('nutrition_plans')->count())->toBe(1)
        ->and($row?->number)->toBe(27)
        ->and($row?->starts_on)->toBe('2026-11-02')
        // El documento de las semanas: dos semanas, seis días cada una, el
        // día de la semana como número ISO y cada plato en su formato.
        ->and($weeks)->toHaveCount(2)
        ->and($weeks[1])->toMatchArray(['number' => 2, 'source' => 'self'])
        ->and($weeks[1]['days'][2])->toEqual([
            'weekday' => 3,
            'lunch' => ['name' => 'Comida del día 10', 'description' => null, 'ingredients' => [
                ['name' => 'Pasta integral', 'quantity' => '80 g'],
            ]],
            'dinner' => ['name' => 'Cena del día 10', 'description' => null, 'ingredients' => []],
        ])
        ->and($weeks[0]['days'][5]['lunch'])->toEqual(['name' => null, 'description' => 'Comida del menú 6', 'ingredients' => []]);
});

it('stores the exchange table and the fixed meals as documents too', function () {
    $plan = NutritionPlanMother::plan();

    (new EloquentNutritionPlanRepository)->save($plan);

    expect(storedDocument($plan->id()->value(), 'exchange_table'))->toEqual([
        'exchanges' => [
            ['category' => 'carbohydrates', 'food' => 'Arroz', 'serving' => '60 g en crudo'],
            ['category' => 'protein', 'food' => 'Pechuga de pollo', 'serving' => '100 g'],
        ],
        'rules' => ['La grasa solo en la comida o en la cena, no en ambas.'],
    ])
        ->and(storedDocument($plan->id()->value(), 'fixed_meals')[0])->toEqual([
            'meal_type' => 'breakfast',
            'description' => 'Café con leche desnatada',
            'option_groups' => [[
                'choose' => 1,
                'options' => ['Tostada de pan integral (40 g) con tomate', '30 g de copos de avena'],
            ]],
        ]);
});

it('keeps a single row when the same plan is saved again', function () {
    // Reemplazar el agregado es reemplazar su fila: no hay tablas hijas que
    // limpiar ni filas huérfanas posibles.
    $repository = new EloquentNutritionPlanRepository;
    $plan = NutritionPlanMother::plan();

    $repository->save($plan);
    $repository->save($plan);

    expect(DB::table('nutrition_plans')->count())->toBe(1);
});

it('rejects at the database level a row whose documents have the wrong shape', function () {
    // El CHECK es la red mínima para lo que no pasa por el dominio (un
    // INSERT a mano): aquí, un plan con una sola semana.
    $insert = fn () => DB::table('nutrition_plans')->insert([
        'id' => '0190a2a0-0000-7000-8000-000000000001',
        'number' => 1,
        'starts_on' => '2026-11-02',
        'fixed_meals' => '[]',
        'exchange_table' => '{"exchanges": [], "rules": []}',
        'weeks' => '[{"number": 1}]',
    ]);

    expect($insert)->toThrow(QueryException::class, 'nutrition_plans_documents_check');
});

it('refuses to load a document with a corrupted value, naming where it is', function () {
    // Un UPDATE a mano deja un número donde debería haber el nombre de un
    // plato. El JSON sigue siendo válido para PostgreSQL, pero no para el
    // mapper, que lo dice con la ruta exacta del dato.
    $repository = new EloquentNutritionPlanRepository;
    $plan = NutritionPlanMother::plan();
    $repository->save($plan);

    DB::statement(
        "UPDATE nutrition_plans SET weeks = jsonb_set(weeks, '{1,days,2,lunch,name}', '42') WHERE id = ?",
        [$plan->id()->value()],
    );

    expect(fn () => $repository->find($plan->id()))
        ->toThrow(UnexpectedValueException::class, 'Plan guardado corrupto: «weeks[1].days[2].lunch.name» debería ser un texto.');
});

it('refuses to load a document that breaks a business rule', function () {
    // Datos bien formados pero que el dominio no admite: el lunes de la
    // semana 1 pasa a ser un segundo martes. Al reconstruir con
    // MenuWeek::create(), la invariante salta igual que al crear el plan.
    $repository = new EloquentNutritionPlanRepository;
    $plan = NutritionPlanMother::plan();
    $repository->save($plan);

    DB::statement(
        "UPDATE nutrition_plans SET weeks = jsonb_set(weeks, '{0,days,0,weekday}', '2') WHERE id = ?",
        [$plan->id()->value()],
    );

    expect(fn () => $repository->find($plan->id()))->toThrow(InvalidMenuWeek::class);
});
