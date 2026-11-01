<?php

declare(strict_types=1);

namespace Recipes\Infrastructure\Http;

use Illuminate\Contracts\View\View;
use Recipes\Application\GetRecipe;

/**
 * RecipePageController: GET /recipes/{id} muestra una receta en una página web.
 *
 * CONCEPTO: segundo adaptador de entrada para el mismo caso de uso
 * Es el gemelo HTML de GetRecipeController (la API). Los dos llaman al MISMO
 * caso de uso (GetRecipe) y reciben el MISMO DTO de lectura (RecipeView);
 * solo cambia cómo se presenta el resultado:
 *
 *   GetRecipeController  → RecipeResource → JSON
 *   RecipePageController → recipes::show  → HTML
 *
 * Esta es la promesa de la arquitectura hexagonal hecha realidad: añadir un
 * canal nuevo (la web) es añadir un adaptador. GetRecipe, RecipeView y el
 * dominio no cambian ni una línea (criterio de aceptación de HEXM-0016).
 *
 * Sin try/catch, como el de la API: si la receta no existe, RecipeNotFound
 * sube y el manejador global (bootstrap/app.php) decide la respuesta según
 * el canal (404 JSON en la API, página 404 en la web).
 *
 * El id llega ya con forma de UUID: la ruta web lleva whereUuid('id'), así
 * que /recipes/abc no llega hasta aquí (decisión de HEXM-0016, ver
 * routes/web.php).
 *
 * CAPA: Infraestructura, adaptador de entrada HTTP (Recipes\Infrastructure\Http).
 */
final readonly class RecipePageController
{
    public function __construct(private GetRecipe $getRecipe) {}

    public function __invoke(string $id): View
    {
        return view('recipes::show', ['recipe' => ($this->getRecipe)($id)]);
    }
}
