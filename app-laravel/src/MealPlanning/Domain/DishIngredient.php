<?php

declare(strict_types=1);

namespace MealPlanning\Domain;

/**
 * DishIngredient: un ingrediente de un plato de la semana 2 ("pasta", "80 g").
 *
 * CONCEPTO: frontera entre bounded contexts
 * El recetario ya tiene un Ingredient con una Quantity exacta (fracción +
 * unidad). MealPlanning NO puede usarlo: los tests de arquitectura prohíben
 * que el núcleo de un contexto importe otro contexto. Las opciones eran:
 *
 *   1. Texto completo por ingrediente ("80 g de pasta").
 *   2. Nombre + cantidad en texto libre ("pasta" + "80 g").  ← elegida
 *   3. Un Quantity propio de MealPlanning (duplicar fracción + unidad).
 *   4. Mover Quantity y Unit al shared kernel (con su ADR).
 *
 * DECISIÓN (HEXM-0012): nombre + cantidad en texto
 * - Respeta la frontera sin acoplar los contextos a un mismo modelo de
 *   cantidad: lo que en el recetario es una medida exacta para cocinar, en un
 *   plan es lo que escribió la persona en su documento ("1 puñado", "½ bote").
 * - Separar el NOMBRE ya deja preparada la lista de la compra (nivel 9): se
 *   puede agrupar por ingrediente aunque la cantidad aún sea texto.
 * - No adelanta complejidad: si la lista de la compra necesita SUMAR
 *   cantidades, se decidirá entonces (Quantity propio o shared kernel) con
 *   requisitos reales. Mientras tanto, duplicar un poco (la normalización
 *   del nombre, igual que en Recipes\Domain\Ingredient) es mejor que acoplar.
 *
 * La cantidad es opcional: null significa "sin cantidad indicada" (al gusto,
 * o simplemente no la apuntó). Un texto en blanco se trata igual que null.
 *
 * CONCEPTO: Value Object, sin identidad y comparado por valor.
 *
 * CAPA: Dominio (MealPlanning\Domain).
 */
final readonly class DishIngredient
{
    private function __construct(
        private string $name,
        private ?string $quantity,
    ) {}

    /**
     * DishIngredient::of('Pasta', '80 g') o DishIngredient::of('Sal').
     */
    public static function of(string $name, ?string $quantity = null): self
    {
        $name = self::normalize($name);

        if ($name === '') {
            throw InvalidDish::ingredientWithoutName();
        }

        $quantity = $quantity === null ? '' : self::normalize($quantity);

        return new self($name, $quantity === '' ? null : $quantity);
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * La cantidad tal como la escribió la persona ("80 g", "½ bote"), o null
     * si no indicó ninguna.
     */
    public function quantity(): ?string
    {
        return $this->quantity;
    }

    /**
     * Igualdad por valor. El nombre se compara sin distinguir mayúsculas, como
     * en el recetario; la cantidad, tal cual (es texto libre: "80 g" y
     * "80 gramos" no se intentan interpretar).
     */
    public function equals(self $other): bool
    {
        return mb_strtolower($this->name) === mb_strtolower($other->name)
            && $this->quantity === $other->quantity;
    }

    /**
     * Recorta los extremos y deja un solo espacio entre palabras. El
     * modificador /u hace que \s funcione con texto UTF-8.
     */
    private static function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
