<?php

declare(strict_types=1);

namespace MealPlanning\Infrastructure;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use MealPlanning\Application\RecipeCatalog;
use MealPlanning\Domain\NutritionPlanRepository;
use MealPlanning\Infrastructure\Persistence\Eloquent\EloquentNutritionPlanRepository;
use MealPlanning\Infrastructure\Recipes\RecipeDeletedListener;
use MealPlanning\Infrastructure\Recipes\RecipesContextRecipeCatalog;
use Recipes\Domain\RecipeDeleted;

/**
 * MealPlanningServiceProvider: la raíz de composición del contexto de planes.
 *
 * CONCEPTO: raíz de composición (composition root), como RecipesServiceProvider
 * Es el ÚNICO sitio que decide qué adaptador concreto hay detrás de cada
 * puerto de MealPlanning. Los casos de uso piden "un NutritionPlanRepository"
 * en su constructor; aquí se dice que, en la aplicación real, es el de
 * Eloquent (HEXM-0020). Los tests unitarios no pasan por aquí: construyen el
 * caso de uso a mano con el de memoria.
 *
 * Un provider por contexto (y no todo en AppServiceProvider): cada bounded
 * context se enchufa a la aplicación por su cuenta, y quitar o cambiar uno
 * no obliga a tocar los demás. Se registra en bootstrap/providers.php.
 *
 * Enchufa los dos puertos de salida del contexto: el repositorio de planes
 * y, desde HEXM-0025, el recetario (RecipeCatalog). El puerto Clock es de
 * Shared y lo enchufa SharedServiceProvider (HEXM-0022). Y, desde HEXM-0027,
 * el adaptador de entrada que escucha eventos de otros contextos
 * (RecipeDeletedListener).
 *
 * CAPA: Infraestructura (MealPlanning\Infrastructure). Conoce los puertos y
 * los adaptadores; el núcleo no lo conoce a él.
 */
final class MealPlanningServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Puerto de salida (Domain) → adaptador de salida (Infrastructure).
        $this->app->bind(NutritionPlanRepository::class, EloquentNutritionPlanRepository::class);

        // Puerto hacia otro contexto (Application) → adaptador anticorrupción
        // que habla con la capa de aplicación de Recipes (HEXM-0025). El
        // GetRecipe que necesita lo construye el contenedor con el binding
        // de RecipesServiceProvider: este provider no sabe cómo se guardan
        // las recetas.
        $this->app->bind(RecipeCatalog::class, RecipesContextRecipeCatalog::class);
    }

    /**
     * CONCEPTO: registrar un oyente (HEXM-0027)
     * Laravel solo descubre solos los listeners de app/Listeners; los de los
     * contextos se registran aquí, a mano: "cuando se publique RecipeDeleted,
     * llama a RecipeDeletedListener::handle()". Es el MealPlanning quien se
     * apunta al evento del recetario, no al revés: el recetario publica sin
     * saber quién escucha (ADR 0018).
     *
     * En boot() y no en register(): el dispatcher es de otro provider, y
     * boot() se ejecuta cuando todos ya están registrados. Laravel inyecta
     * el Dispatcher como argumento (inyección de métodos).
     */
    public function boot(Dispatcher $events): void
    {
        $events->listen(RecipeDeleted::class, RecipeDeletedListener::class);
    }
}
