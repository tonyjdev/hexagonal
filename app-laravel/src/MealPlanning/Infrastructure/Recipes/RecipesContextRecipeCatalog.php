<?php

declare(strict_types=1);

namespace MealPlanning\Infrastructure\Recipes;

use MealPlanning\Application\RecipeCatalog;
use MealPlanning\Domain\RecipeReference;
use Recipes\Application\GetRecipe;
use Recipes\Application\RecipeNotFound;

/**
 * RecipesContextRecipeCatalog: el puerto RecipeCatalog respondido por el
 * contexto Recipes de esta misma aplicación.
 *
 * CONCEPTO: capa anticorrupción (anti-corruption layer, ACL)
 * Cuando un contexto necesita algo de otro, no importa su modelo: lo
 * TRADUCE en la frontera. Aquí la traducción va en los dos sentidos:
 *
 *     MealPlanning                       Recipes
 *     RecipeReference ──value()──►  string (el id que espera GetRecipe)
 *     true            ◄──────────   RecipeView (la receta existe)
 *     false           ◄──────────   RecipeNotFound (no existe)
 *
 * Nada de Recipes sale de esta clase: ni el RecipeView, ni la excepción. Si
 * mañana el recetario cambia su caso de uso, o pasa a ser un servicio HTTP,
 * solo cambia este adaptador; el núcleo de MealPlanning ni se entera. Es lo
 * que "anticorrupción" quiere decir: el modelo de fuera no "corrompe" (no
 * se cuela en) el modelo propio.
 *
 * DECISIÓN: entrar por la capa de APLICACIÓN de Recipes, nunca por su dominio
 * ni por su infraestructura (HEXM-0025, ADR 0017)
 * Recipes\Application es la API pública del recetario: sus casos de uso y
 * DTO. Usar RecipeRepository o la tabla recipes directamente se saltaría sus
 * reglas y acoplaría MealPlanning a cómo guarda las recetas. Lo comprueba un
 * test de arquitectura (HexagonalLayersTest).
 *
 * DECISIÓN: reutilizar GetRecipe, sin una consulta nueva en Recipes
 * GetRecipe carga la receta entera para responder un sí o un no. Es más de lo
 * necesario, pero el alta de un plan ocurre cada dos meses y referencia unas
 * pocas recetas: no compensa añadir un caso de uso "¿existe?" al recetario.
 * Si algún día pesa, se añade RecipeExists en Recipes\Application y solo
 * cambia este adaptador.
 *
 * Por qué no se captura InvalidRecipeId (id mal formado): RecipeReference y
 * RecipeId validan igual (los dos usan Shared\Domain\Uuid), así que aquí
 * nunca llega un id mal formado. Y capturarlo obligaría a usar Recipes\Domain,
 * justo lo que el test de arquitectura prohíbe. Si las dos validaciones
 * dejaran de coincidir, la excepción saldría tal cual, como un 422.
 *
 * CAPA: Infraestructura (MealPlanning\Infrastructure\Recipes): adaptador de
 * salida. El subespacio Recipes agrupa lo que habla con ese contexto.
 */
final readonly class RecipesContextRecipeCatalog implements RecipeCatalog
{
    public function __construct(private GetRecipe $getRecipe) {}

    public function exists(RecipeReference $recipe): bool
    {
        try {
            ($this->getRecipe)($recipe->value());

            return true;
        } catch (RecipeNotFound) {
            // "No existe" es una respuesta normal de la consulta de Recipes;
            // para MealPlanning, un simple false.
            return false;
        }
    }
}
