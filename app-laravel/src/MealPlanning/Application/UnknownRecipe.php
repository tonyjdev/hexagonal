<?php

declare(strict_types=1);

namespace MealPlanning\Application;

use MealPlanning\Domain\Dish;
use MealPlanning\Domain\RecipeReference;
use Shared\Domain\DomainException;

/**
 * UnknownRecipe: un plato apunta a una receta que no existe en el recetario.
 *
 * Es la regla 6 («un plato puede referenciar una receta, y esa receta debe
 * existir») incumplida al dar de alta un plan. La lanza RegisterNutritionPlan
 * cuando el puerto RecipeCatalog responde que no.
 *
 * DECISIÓN: un error de negocio (422), no un "no encontrado" (404)
 * Aunque la receta "no se encuentra", lo que se está pidiendo es dar de alta
 * un PLAN, y ese plan existe en la petición: lo que falla es uno de sus
 * datos. Por eso hereda de DomainException, como un plan que empieza en
 * martes, y no de Shared\Application\NotFound, que diría "el recurso que
 * pides no existe" (ver NotFound y ADR 0007).
 *
 * DECISIÓN: vive en Application, junto a su puerto
 * El dominio de MealPlanning no puede detectar este error: no tiene forma de
 * saber qué recetas hay. Lo detecta el caso de uso al preguntar al puerto, y
 * la excepción acompaña al puerto (como NoPlanInForce acompaña a la consulta
 * del plan vigente). Puede heredar de Shared\Domain\DomainException: la
 * aplicación siempre puede usar el dominio compartido.
 *
 * CAPA: Aplicación (MealPlanning\Application).
 */
final class UnknownRecipe extends DomainException
{
    public static function referencedBy(Dish $dish, RecipeReference $recipe): self
    {
        // Un plato de la semana 2 se identifica por su nombre corto; uno de
        // la semana 1, por su descripción. Siempre tiene uno de los dos
        // (invariante de Dish).
        return new self(sprintf(
            'El plato «%s» apunta a una receta que no existe en el recetario («%s»).',
            $dish->name() ?? $dish->description(),
            $recipe->value(),
        ));
    }
}
