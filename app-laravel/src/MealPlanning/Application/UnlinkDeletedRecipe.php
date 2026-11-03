<?php

declare(strict_types=1);

namespace MealPlanning\Application;

use MealPlanning\Domain\InvalidRecipeReference;
use MealPlanning\Domain\NutritionPlanRepository;
use MealPlanning\Domain\RecipeReference;

/**
 * UnlinkDeletedRecipe: caso de uso "una receta del recetario ya no existe:
 * desvincúlala de los planes que la usan".
 *
 * CONCEPTO: reaccionar a un hecho de otro contexto con un caso de uso PROPIO
 * (HEXM-0027, ADR 0019)
 * El recetario anuncia RecipeDeleted (HEXM-0026). Este caso de uso no sabe
 * nada de ese evento ni de quién lo emite: recibe un id de receta (texto,
 * como un comando) y aplica la regla de MealPlanning. Quien traduce el
 * evento a esta llamada es el oyente de Infrastructure
 * (RecipeDeletedListener), la capa anticorrupción en la dirección de los
 * eventos. Mañana lo podría llamar también un comando de Artisan.
 *
 *     1. NutritionPlanRepository::usingRecipe(ref)   ← solo los planes afectados
 *     2. $plan->withoutRecipe(ref)                    ← regla 24, en el agregado
 *     3. NutritionPlanRepository::save(versión nueva) ← mismo id: reemplaza
 *
 * Regla 24: los platos se DESVINCULAN. Conservan su nombre, su descripción y
 * sus ingredientes; solo dejan de apuntar a la receta. El plan sigue completo
 * y válido.
 *
 * CONCEPTO: consistencia eventual entre contextos
 * El borrado de la receta y este cambio NO van en la misma transacción: el
 * recetario ya confirmó el suyo antes de publicar el evento (ADR 0018). Hay
 * un instante en que la receta ya no existe y el plan aún apunta a ella; ese
 * estado intermedio ya estaba previsto (una RecipeReference puede quedar
 * colgando, ADR 0016). Cada plan se guarda por separado: si uno fallara, los
 * ya guardados quedan bien, y volver a ejecutar el caso de uso termina el
 * trabajo, porque es IDEMPOTENTE (un plan ya desvinculado no aparece en
 * usingRecipe()).
 *
 * CAPA: Aplicación (MealPlanning\Application). No conoce Recipes.
 */
final readonly class UnlinkDeletedRecipe
{
    public function __construct(private NutritionPlanRepository $plans) {}

    /**
     * Un comando (CQS): cambia los planes y no devuelve nada. Si ningún plan
     * usaba la receta, no hace nada.
     *
     * @throws InvalidRecipeReference si el id no es un UUID
     */
    public function __invoke(string $recipeId): void
    {
        $recipe = RecipeReference::fromString($recipeId);

        foreach ($this->plans->usingRecipe($recipe) as $plan) {
            $this->plans->save($plan->withoutRecipe($recipe));
        }
    }
}
