<?php

declare(strict_types=1);

namespace MealPlanning\Infrastructure;

use MealPlanning\Application\RecipeCatalog;
use MealPlanning\Domain\RecipeReference;

/**
 * InMemoryRecipeCatalog: un recetario de mentira con las recetas que se le
 * dan al construirlo.
 *
 * CONCEPTO: fake (doble de test) de un puerto
 * Con él, RegisterNutritionPlan se prueba sin Laravel, sin PostgreSQL y SIN
 * EL CONTEXTO Recipes: el test de MealPlanning decide qué recetas "existen"
 * y no tiene que crear recetas de verdad. Cumple los mismos tests de
 * contrato que el adaptador real (RecipeCatalogContractTest), así que lo que
 * prueban los tests rápidos vale también en producción.
 *
 * Como InMemoryNutritionPlanRepository, vive en Infrastructure aunque no use
 * Laravel: es un adaptador más del puerto.
 *
 * CAPA: Infraestructura (MealPlanning\Infrastructure).
 */
final readonly class InMemoryRecipeCatalog implements RecipeCatalog
{
    /**
     * Los ids de las recetas que existen, como texto normalizado.
     *
     * @var list<string>
     */
    private array $recipes;

    public function __construct(RecipeReference ...$recipes)
    {
        $this->recipes = array_values(array_map(fn (RecipeReference $recipe): string => $recipe->value(), $recipes));
    }

    public function exists(RecipeReference $recipe): bool
    {
        return in_array($recipe->value(), $this->recipes, true);
    }
}
