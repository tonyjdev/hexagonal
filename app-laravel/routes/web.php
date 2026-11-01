<?php

use Illuminate\Support\Facades\Route;
use Recipes\Infrastructure\Http\RecipePageController;

Route::get('/', function () {
    return view('welcome');
});

/*
| Recetario: página web de una receta (HEXM-0016).
|
| Mismo caso de uso que GET /api/recipes/{id}, distinto adaptador (HTML en
| vez de JSON). Tiene su propio nombre (recipes.page) para no confundirse con
| la ruta de la API (recipes.show).
|
| DECISIÓN: whereUuid('id') en la web, pero no en la API (HEXM-0016)
| Para una persona que escribe una URL en el navegador, /recipes/abc y un
| UUID inexistente son lo mismo: esa receta no existe. Con la restricción,
| Laravel responde 404 sin llegar al controlador, con nuestra página de error
| en español (resources/views/errors/404.blade.php). La API, en cambio,
| responde 422 con el mensaje del dominio (ADR 0008), porque a un cliente
| programático le interesa distinguir "no puede existir" de "no existe".
*/
Route::get('/recipes/{id}', RecipePageController::class)
    ->whereUuid('id')
    ->name('recipes.page');
