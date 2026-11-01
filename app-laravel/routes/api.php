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
use Recipes\Infrastructure\Http\CreateRecipeController;

// Recetario: alta de recetas (HEXM-0010).
Route::post('/recipes', CreateRecipeController::class);
