<?php

declare(strict_types=1);

namespace Recipes\Domain;

use Shared\Domain\Uuid;

/**
 * RecipeId: identificador de una receta.
 *
 * CONCEPTO: identificador como Value Object
 * La receta es una ENTIDAD: tiene identidad y sigue siendo "la misma receta"
 * aunque cambie su nombre o sus pasos. Esa identidad es este value object.
 * Envolver el UUID en un tipo propio, en vez de pasar strings, impide
 * confundir un RecipeId con otro identificador cualquiera, y PHPStan lo
 * comprueba en cada firma de método.
 *
 * Es además LO ÚNICO que el contexto MealPlanning sabrá de una receta (un
 * Dish podrá referenciar un RecipeId, nivel 6).
 *
 * DECISIÓN: el dominio genera el identificador (UUID v7), no la base de datos
 * La receta nace con su identidad, se puede crear y probar en memoria y el
 * repositorio solo la guarda. Ver docs/adr/0005-identificadores-uuid-v7-en-el-dominio.md.
 *
 * CONCEPTO: composición con el shared kernel (HEXM-0012)
 * La generación y la validación del UUID viven ahora en Shared\Domain\Uuid,
 * porque MealPlanning también las necesita (NutritionPlanId). RecipeId TIENE
 * un Uuid dentro y le delega el trabajo técnico, pero conserva su API
 * (generate, fromString, value, equals) y su propia excepción de dominio:
 * para quien lo usa, nada ha cambiado.
 *
 * CAPA: Dominio (Recipes\Domain). Solo PHP nativo y el shared kernel.
 */
final readonly class RecipeId
{
    private function __construct(private Uuid $uuid) {}

    /**
     * Genera un identificador nuevo (UUID v7, ordenado por fecha de creación).
     */
    public static function generate(): self
    {
        return new self(Uuid::v7());
    }

    /**
     * Reconstruye un identificador que ya existe (al leerlo de la base de
     * datos o de una petición HTTP). Acepta cualquier versión de UUID bien
     * formada y la normaliza a minúsculas.
     *
     * El operador `?? throw` (PHP 8) convierte el null de tryFromString() en
     * el error de dominio propio de las recetas.
     */
    public static function fromString(string $value): self
    {
        return new self(Uuid::tryFromString($value) ?? throw InvalidRecipeId::malformed($value));
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
