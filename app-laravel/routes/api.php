<?php

/*
|--------------------------------------------------------------------------
| Rutas de la API REST
|--------------------------------------------------------------------------
|
| CONCEPTO: punto de entrada de los adaptadores driving (de entrada)
| En la arquitectura hexagonal, una ruta HTTP es la "puerta" por la que el
| mundo exterior llega a un adaptador de entrada (un controlador), que a su
| vez traduce la petición a un comando y llama al caso de uso. Este fichero
| solo conecta URL ↔ controlador: aquí no hay lógica.
|
| Laravel aplica automáticamente a este fichero (por registrarlo con `api:`
| en bootstrap/app.php):
|   - el prefijo `/api` en todas las URL (Route::post('/recipes') responde
|     en /api/recipes);
|   - el grupo de middleware `api`, sin sesión, cookies ni CSRF, porque una
|     API REST es sin estado (stateless).
|
| DECISIÓN: se crea a mano en vez de con `php artisan install:api`, porque
| ese comando instala además Laravel Sanctum (autenticación por tokens), y la
| autenticación está fuera del alcance por ahora (HEXM-0010).
|
| Los controladores NO viven en app/Http: cada uno está en la capa de
| infraestructura de su bounded context (Recipes\Infrastructure\Http), igual
| que el repositorio de Eloquent vive en Recipes\Infrastructure\Persistence.
| Laravel es un adaptador más, no el centro de la aplicación.
*/

use Illuminate\Support\Facades\Route;
use MealPlanning\Infrastructure\Http\AttachSelfWeekController;
use MealPlanning\Infrastructure\Http\ConfirmPlanImportController;
use MealPlanning\Infrastructure\Http\GetMenuForDateController;
use MealPlanning\Infrastructure\Http\GetNutritionPlanController;
use MealPlanning\Infrastructure\Http\GetPlanImportController;
use MealPlanning\Infrastructure\Http\ListNutritionPlansController;
use MealPlanning\Infrastructure\Http\RegisterNutritionPlanController;
use MealPlanning\Infrastructure\Http\ReplaceNutritionistPlanController;
use MealPlanning\Infrastructure\Http\StartPlanImportController;
use Recipes\Infrastructure\Http\CreateRecipeController;
use Recipes\Infrastructure\Http\DeleteRecipeController;
use Recipes\Infrastructure\Http\GetRecipeController;

// Recetario: alta de recetas (HEXM-0010) y consulta (HEXM-0011).
//
// Con nombre (->name()) para generar sus URL con route('recipes.show', ...)
// en vez de escribirlas a mano: si la URL cambia, se cambia solo aquí. La
// usa la cabecera Location del alta.
//
// Sin whereUuid() en {id}: la forma del identificador la valida el dominio
// (InvalidRecipeId → 422 en español), decisión de HEXM-0011. Con la
// restricción, Laravel respondería un 404 en inglés antes de llegar al
// controlador.
Route::post('/recipes', CreateRecipeController::class)->name('recipes.store');
Route::get('/recipes/{id}', GetRecipeController::class)->name('recipes.show');
Route::delete('/recipes/{id}', DeleteRecipeController::class)->name('recipes.destroy');

// Planes nutricionales: alta manual de un plan completo (HEXM-0021), el
// histórico y la consulta de un plan (HEXM-0023). Como en las recetas, sin
// whereUuid() en {id}: la forma del id la valida el dominio (422 en español).
Route::get('/nutrition-plans', ListNutritionPlansController::class)->name('nutrition-plans.index');
Route::post('/nutrition-plans', RegisterNutritionPlanController::class)->name('nutrition-plans.store');
Route::get('/nutrition-plans/{id}', GetNutritionPlanController::class)->name('nutrition-plans.show');

// "¿Qué me toca comer hoy?" (HEXM-0022): sin parámetros, hoy según el reloj
// (Clock → SystemClock en la zona del usuario); con ?date=AAAA-MM-DD, ese día.
Route::get('/menu', GetMenuForDateController::class)->name('menu.show');

// Importación de un plan desde sus dos Excel (HEXM-0033, sobre los casos de
// uso de HEXM-0032). El orden de uso:
//   1. POST   /plan-imports                        Excel de la nutricionista → 201
//   2. PUT    /plan-imports/{id}/self-week         Excel de la semana 2
//      PUT    /plan-imports/{id}/nutritionist-plan sustituir el de la nutricionista
//   3. POST   /plan-imports/{id}/confirm           {"starts_on": "AAAA-MM-DD"} → 201, el plan
//   y en cualquier momento GET /plan-imports/{id}  qué tiene y qué le falta.
// Los Excel llegan como multipart/form-data en el campo `file`. Como en los
// planes, sin whereUuid(): el id lo valida el dominio (422 en español).
Route::post('/plan-imports', StartPlanImportController::class)->name('plan-imports.store');
Route::get('/plan-imports/{id}', GetPlanImportController::class)->name('plan-imports.show');
Route::put('/plan-imports/{id}/self-week', AttachSelfWeekController::class)->name('plan-imports.self-week');
Route::put('/plan-imports/{id}/nutritionist-plan', ReplaceNutritionistPlanController::class)->name('plan-imports.nutritionist-plan');
Route::post('/plan-imports/{id}/confirm', ConfirmPlanImportController::class)->name('plan-imports.confirm');
