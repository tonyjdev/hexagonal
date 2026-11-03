<?php

declare(strict_types=1);

namespace MealPlanning\Infrastructure\Recipes;

use Illuminate\Contracts\Debug\ExceptionHandler;
use MealPlanning\Application\UnlinkDeletedRecipe;
use Recipes\Domain\RecipeDeleted;
use Throwable;

/**
 * RecipeDeletedListener: cuando el recetario borra una receta, los planes
 * desvinculan sus platos de ella.
 *
 * CONCEPTO: un oyente (listener) es un adaptador de ENTRADA (HEXM-0027,
 * ADR 0019)
 * Como un controlador traduce una petición HTTP a un caso de uso, el oyente
 * traduce un EVENTO a un caso de uso:
 *
 *     HTTP       → RegisterNutritionPlanController → RegisterNutritionPlan
 *     evento     → RecipeDeletedListener           → UnlinkDeletedRecipe
 *
 * Es la capa anticorrupción en la dirección de los eventos: el evento es
 * del recetario (Recipes\Domain\RecipeDeleted), pero de él solo sale el id
 * de la receta como texto hacia el caso de uso, que habla el idioma de
 * MealPlanning (RecipeReference). Ni el núcleo de MealPlanning conoce el
 * evento, ni el recetario sabe que este oyente existe: lo registra
 * MealPlanningServiceProvider en el dispatcher de Laravel, al que publica
 * LaravelEventBus (ADR 0018).
 *
 * DECISIÓN: los eventos de un contexto son su contrato público (ADR 0019)
 * Este es el único sitio de MealPlanning que usa algo de Recipes\Domain, y
 * solo el evento. El test de arquitectura lo autoriza con una lista
 * explícita (publicEvents()) y sigue prohibiendo el resto de Recipes\Domain
 * (RecipeId, Recipe...) y de Recipes\Infrastructure.
 *
 * DECISIÓN: si la reacción falla, se informa del error pero no se tumba el
 * borrado
 * El oyente se ejecuta en la misma petición que DELETE /api/recipes/{id},
 * cuando la receta ya está borrada y confirmada. Si la excepción subiera, el
 * cliente recibiría un 500 por un borrado que SÍ ha ocurrido. Por eso se
 * captura y se manda al manejador de excepciones (logs, Sentry...): la receta
 * queda borrada, y los planes que no se hayan podido actualizar siguen
 * apuntando a ella, un estado ya previsto (ADR 0016). Volver a lanzar
 * UnlinkDeletedRecipe lo arregla, porque es idempotente. Es la consistencia
 * eventual llevada a su forma más simple; la versión robusta (cola con
 * reintentos) queda fuera de alcance.
 *
 * CAPA: Infraestructura (MealPlanning\Infrastructure\Recipes), junto a la
 * otra pieza que habla con el recetario (RecipesContextRecipeCatalog).
 */
final readonly class RecipeDeletedListener
{
    public function __construct(
        private UnlinkDeletedRecipe $unlinkDeletedRecipe,
        private ExceptionHandler $exceptions,
    ) {}

    /**
     * Laravel llama a handle() con el evento: el tipo del parámetro es lo que
     * conecta este oyente con RecipeDeleted.
     */
    public function handle(RecipeDeleted $event): void
    {
        try {
            ($this->unlinkDeletedRecipe)($event->recipeId());
        } catch (Throwable $exception) {
            $this->exceptions->report($exception);
        }
    }
}
