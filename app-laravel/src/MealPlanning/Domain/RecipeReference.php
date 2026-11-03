<?php

declare(strict_types=1);

namespace MealPlanning\Domain;

use Shared\Domain\Uuid;

/**
 * RecipeReference: la receta del recetario a la que apunta un plato.
 *
 *     menú 6, comida: "Guiso de alubias y pollo" → receta 0190a…
 *
 * CONCEPTO: referencia a un agregado de OTRO bounded context, solo por su
 * identidad (ADR 0010, ADR 0016)
 * Para un plan, una receta es solo eso: una referencia. MealPlanning no
 * conoce sus ingredientes ni sus pasos, ni puede cargarla: si los necesitara,
 * los pediría a través de un puerto propio (HEXM-0025). Guardar el objeto
 * Recipe dentro del plato acoplaría los dos agregados (y los dos contextos):
 * cambiar una receta obligaría a tocar los planes que la usan.
 *
 * DECISIÓN: un value object propio, no el RecipeId del recetario (HEXM-0024)
 * Los tests de arquitectura prohíben que el núcleo de MealPlanning use
 * Recipes\Domain, y es lo correcto: cada contexto modela los conceptos a su
 * manera. Lo ÚNICO que comparten es el valor del UUID, y la técnica para
 * manejarlo (Shared\Domain\Uuid, el shared kernel), igual que NutritionPlanId
 * y RecipeId. La alternativa, mover RecipeId a Shared, metería un concepto
 * del recetario en el núcleo común: el README de Shared advierte justo
 * contra eso (el ejemplo de Quantity).
 *
 * El nombre no es casual: "RecipeId" en Recipes es la IDENTIDAD de su
 * agregado; "RecipeReference" en MealPlanning es una REFERENCIA hacia fuera,
 * que podría apuntar a algo que ya no existe (HEXM-0027).
 *
 * CAPA: Dominio (MealPlanning\Domain). Solo PHP nativo y el shared kernel.
 */
final readonly class RecipeReference
{
    private function __construct(private Uuid $uuid) {}

    /**
     * Una referencia a partir del id de la receta. Acepta cualquier UUID bien
     * formado y lo normaliza a minúsculas (como los identificadores propios).
     * No comprueba que la receta exista: eso no lo puede saber el dominio de
     * MealPlanning (HEXM-0025).
     */
    public static function fromString(string $value): self
    {
        return new self(Uuid::tryFromString($value) ?? throw InvalidRecipeReference::malformed($value));
    }

    public function value(): string
    {
        return $this->uuid->value();
    }

    public function equals(self $other): bool
    {
        return $this->uuid->equals($other->uuid);
    }
}
