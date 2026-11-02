<?php

/*
| Tests de NutritionPlanId: identificador de un plan, UUID v7 generado en el
| dominio. El formato en sí se prueba en UuidTest (shared kernel); aquí, lo
| propio del plan: su error de dominio y su igualdad.
*/

use MealPlanning\Domain\InvalidNutritionPlanId;
use MealPlanning\Domain\NutritionPlanId;

it('generates a UUID version 7', function () {
    expect(NutritionPlanId::generate()->value())
        ->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
});

it('is rebuilt from an existing identifier, normalized to lowercase', function () {
    expect(NutritionPlanId::fromString('019A3B2C-7D4E-7F00-8A1B-2C3D4E5F6A7B')->value())
        ->toBe('019a3b2c-7d4e-7f00-8a1b-2c3d4e5f6a7b');
});

it('rejects malformed identifiers with its own domain error', function () {
    // El mismo texto inválido da un error que habla de PLANES, no de UUID.
    NutritionPlanId::fromString('plan-27');
})->throws(InvalidNutritionPlanId::class, '"plan-27" no es un identificador de plan válido.');

it('is compared by value', function () {
    $id = NutritionPlanId::generate();

    expect($id->equals(NutritionPlanId::fromString($id->value())))->toBeTrue()
        ->and($id->equals(NutritionPlanId::generate()))->toBeFalse();
});
