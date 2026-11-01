<?php

declare(strict_types=1);

namespace Recipes\Domain;

/**
 * Ingredient: un ingrediente de una receta ("½ bote de lentejas", "sal al
 * gusto").
 *
 * CONCEPTO: Value Object (y no entidad)
 * Un ingrediente no tiene identidad propia ni vida fuera de su receta: "200 g
 * de arroz" en una receta es intercambiable por otro "200 g de arroz". No
 * necesita un IngredientId. Si cambia la cantidad, no "modificamos el
 * ingrediente": la receta pasa a tener OTRO ingrediente. Por eso es
 * inmutable y se compara por valor con equals().
 * Compáralo con Recipe, que sí es una entidad: sigue siendo la misma receta
 * aunque cambie su nombre.
 *
 * DECISIÓN: la cantidad es opcional ("al gusto")
 * Se decidió en HEXM-0005: "sal al gusto" o "una pizca de pimienta" no son
 * una Quantity, sino un ingrediente SIN cantidad (quantity() devuelve null).
 * Para que esto se lea en el lenguaje del negocio hay dos constructores con
 * nombre: Ingredient::of('Arroz', $quantity) e Ingredient::toTaste('Sal').
 *
 * DECISIÓN: el nombre se compara sin distinguir mayúsculas ni espacios
 * "Cebolla", "cebolla" y " cebolla " son el mismo ingrediente. Se guarda el
 * nombre tal como lo escribió la persona (recortado y con un solo espacio
 * entre palabras) para mostrarlo, y se compara en minúsculas. Esa misma
 * comparación es la que usa Recipe para prohibir ingredientes repetidos.
 *
 * CAPA: Dominio (Recipes\Domain).
 */
final readonly class Ingredient
{
    private function __construct(
        private string $name,
        private ?Quantity $quantity,
    ) {}

    /**
     * Ingrediente con cantidad: Ingredient::of('Lentejas', Quantity::fromString('½', Unit::Jar)).
     */
    public static function of(string $name, Quantity $quantity): self
    {
        return new self(self::normalizeName($name), $quantity);
    }

    /**
     * Ingrediente sin cantidad: Ingredient::toTaste('Sal').
     */
    public static function toTaste(string $name): self
    {
        return new self(self::normalizeName($name), null);
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * La cantidad, o null si es "al gusto".
     *
     * CONCEPTO: tipo nullable (?Quantity)
     * El `?` obliga a quien lo use a tratar el caso "sin cantidad": PHPStan
     * avisa si alguien llama a $ingredient->quantity()->unit() sin comprobar
     * antes que no es null.
     */
    public function quantity(): ?Quantity
    {
        return $this->quantity;
    }

    public function isToTaste(): bool
    {
        return $this->quantity === null;
    }

    /**
     * ¿Es el mismo ingrediente, aunque la cantidad sea distinta?
     * "Cebolla (1 unidad)" y "cebolla (2 unidades)" lo son.
     */
    public function hasSameNameAs(self $other): bool
    {
        return mb_strtolower($this->name) === mb_strtolower($other->name);
    }

    /**
     * Igualdad por valor: mismo ingrediente y misma cantidad (o los dos al
     * gusto).
     */
    public function equals(self $other): bool
    {
        if (! $this->hasSameNameAs($other)) {
            return false;
        }

        if ($this->quantity === null || $other->quantity === null) {
            return $this->quantity === $other->quantity;
        }

        return $this->quantity->equals($other->quantity);
    }

    /**
     * Recorta los extremos y deja un solo espacio entre palabras
     * ("aceite   de oliva " → "aceite de oliva"). El modificador /u hace que
     * \s funcione con texto UTF-8.
     */
    private static function normalizeName(string $name): string
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $name));

        if ($normalized === '') {
            throw InvalidIngredient::emptyName();
        }

        return $normalized;
    }
}
