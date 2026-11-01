<?php

declare(strict_types=1);

namespace Tests\Support\Recipes;

use Recipes\Domain\Ingredient;
use Recipes\Domain\Quantity;
use Recipes\Domain\Recipe;
use Recipes\Domain\RecipeId;
use Recipes\Domain\Servings;
use Recipes\Domain\Step;
use Recipes\Domain\Unit;

/**
 * RecipeMother: recetas de ejemplo para los tests.
 *
 * CONCEPTO: Object Mother
 * Un patrón de tests: una clase que "da a luz" objetos de ejemplo ya
 * válidos, para que cada test no tenga que montar a mano una receta con sus
 * ingredientes. Si mañana Recipe pide un dato más, solo cambia aquí.
 * (En Laravel, el equivalente para modelos de Eloquent son las factories;
 * aquí no sirven porque Recipe no es un modelo de Eloquent.)
 *
 * Datos ficticios inspirados en la semana 1 del plan (CONVENCIONES.md, §6).
 */
final class RecipeMother
{
    /**
     * Una receta "completa": cantidades enteras, fracciones (½, 1 ½), un
     * ingrediente al gusto y varios pasos. Cubre todos los casos que un
     * adaptador tiene que saber guardar.
     */
    public static function lentilsWithPisto(?RecipeId $id = null): Recipe
    {
        return Recipe::create(
            $id ?? RecipeId::generate(),
            'Lentejas con pisto',
            Servings::of(2),
            [
                Ingredient::of('Lentejas cocidas', Quantity::fraction(1, 2, Unit::Jar)),
                Ingredient::of('Pisto', Quantity::of(200, Unit::Gram)),
                Ingredient::of('Aceite de oliva', Quantity::fromString('1 ½', Unit::Tablespoon)),
                Ingredient::toTaste('Sal'),
            ],
            [
                Step::fromString('Calentar el pisto en una sartén'),
                Step::fromString('Añadir las lentejas escurridas'),
                Step::fromString('Cocinar 5 minutos y salar al gusto'),
            ],
        );
    }

    /**
     * Una receta mínima: un ingrediente al gusto y sin pasos.
     */
    public static function chickpeaSalad(?RecipeId $id = null): Recipe
    {
        return Recipe::create($id ?? RecipeId::generate(), 'Ensalada de garbanzos', Servings::of(1), [
            Ingredient::toTaste('Garbanzos'),
        ]);
    }

    /**
     * Foto de TODO el estado de una receta en datos primitivos.
     *
     * Recipe::equals() compara solo la identidad (es una entidad), así que
     * no sirve para comprobar que un repositorio devuelve LOS MISMOS DATOS.
     * Con esta foto, expect(snapshot($a))->toBe(snapshot($b)) compara nombre,
     * raciones, ingredientes (con su fracción exacta) y pasos, en orden.
     *
     * @return array{id: string, name: string, servings: int, ingredients: list<array{name: string, quantity: string|null}>, steps: list<string>}
     */
    public static function snapshot(Recipe $recipe): array
    {
        return [
            'id' => $recipe->id()->value(),
            'name' => $recipe->name(),
            'servings' => $recipe->servings()->value(),
            'ingredients' => array_map(
                fn (Ingredient $ingredient): array => [
                    'name' => $ingredient->name(),
                    // "1/2 jar", o null si es al gusto.
                    'quantity' => ($quantity = $ingredient->quantity()) === null
                        ? null
                        : "{$quantity->numerator()}/{$quantity->denominator()} {$quantity->unit()->value}",
                ],
                $recipe->ingredients(),
            ),
            'steps' => array_map(fn (Step $step): string => $step->text(), $recipe->steps()),
        ];
    }
}
