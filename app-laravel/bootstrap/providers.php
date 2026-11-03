<?php

use App\Providers\AppServiceProvider;
use MealPlanning\Infrastructure\MealPlanningServiceProvider;
use Recipes\Infrastructure\RecipesServiceProvider;
use Shared\Infrastructure\SharedServiceProvider;

/*
| Service providers de la aplicación.
|
| Además del de Laravel (AppServiceProvider), cada bounded context de src/
| registra aquí su propio provider: es su raíz de composición, donde se
| enchufa cada puerto a su adaptador.
*/
return [
    AppServiceProvider::class,
    SharedServiceProvider::class,
    RecipesServiceProvider::class,
    MealPlanningServiceProvider::class,
];
