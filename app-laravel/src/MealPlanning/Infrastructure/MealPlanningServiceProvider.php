<?php

declare(strict_types=1);

namespace MealPlanning\Infrastructure;

use Illuminate\Support\ServiceProvider;
use MealPlanning\Domain\NutritionPlanRepository;
use MealPlanning\Infrastructure\Persistence\Eloquent\EloquentNutritionPlanRepository;

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
 * Lo que aún no está: el binding del puerto Clock (Shared\Application) a
 * SystemClock, que llega con GET /api/menu en HEXM-0022.
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
    }
}
