<?php

declare(strict_types=1);

namespace MealPlanning\Domain;

/**
 * Dish: un plato de la comida o de la cena de un día de menú.
 *
 * CONCEPTO: un value object para dos formatos de un mismo concepto
 * Los planes traen los platos de dos maneras (ver docs/DOMINIO.md):
 *
 *   - Semana 1 (la nutricionista): solo texto libre.
 *       "Lentejas (½ bote) con pisto de calabacín, cebolla y tomate"
 *   - Semana 2 (el usuario): un nombre corto y una lista de ingredientes.
 *       "Pasta con salmón y pesto" + [pasta 80 g, salmón 100 g, pesto...]
 *
 * Es el MISMO concepto del negocio (lo que hay que comer en esa comida), así
 * que es una sola clase, no dos. Cada formato tiene su CONSTRUCTOR CON NOMBRE
 * (named constructor): Dish::described() y Dish::named(). Se leen en el
 * lenguaje del negocio y cada uno exige justo lo que trae su formato.
 *
 * Por qué no dos clases (FreeTextDish y StructuredDish) con una interfaz: el
 * día de menú, el calendario o la lista de la compra tratan los platos igual
 * y solo preguntan "¿tiene nombre?, ¿tiene ingredientes?". Una jerarquía
 * obligaría a comprobar tipos (instanceof) por todas partes.
 *
 * Por qué no guarda el WeekSource: el origen es de la SEMANA, no del plato.
 * Lo sabe MenuWeek (HEXM-0013), y un plato no necesita conocerlo.
 *
 * INVARIANTE: un plato no puede estar vacío. Debe tener nombre (semana 2) o
 * descripción (semana 1). Los ingredientes son opcionales: algunos platos
 * propios ("Sobras del sábado") no los detallan.
 *
 * CONCEPTO: referencia opcional a una receta (HEXM-0024)
 * Un plato puede apuntar a una receta del recetario (RecipeReference): el
 * guiso de alubias del menú 6 tiene su receta. Es opcional y vale para los
 * dos formatos. Se añade con withRecipe(), que devuelve un plato NUEVO: el
 * value object sigue siendo inmutable. Que la receta exista no lo comprueba
 * el plato (no puede saberlo): lo hará el alta a través de un puerto
 * (HEXM-0025).
 *
 * CAPA: Dominio (MealPlanning\Domain).
 */
final readonly class Dish
{
    /**
     * @param  list<DishIngredient>  $ingredients
     */
    private function __construct(
        private ?string $name,
        private ?string $description,
        private array $ingredients,
        private ?RecipeReference $recipe = null,
    ) {}

    /**
     * Plato de la semana 1: solo descripción en texto libre.
     *
     * La descripción se recorta pero NO se compactan sus espacios interiores:
     * es texto libre de un documento y puede traer saltos de línea con
     * sentido ("Lentejas (½ bote)\ncon pisto").
     */
    public static function described(string $description): self
    {
        $description = trim($description);

        if ($description === '') {
            throw InvalidDish::empty();
        }

        return new self(null, $description, []);
    }

    /**
     * Plato de la semana 2: nombre corto y, opcionalmente, sus ingredientes.
     *
     * @param  list<DishIngredient>  $ingredients
     */
    public static function named(string $name, array $ingredients = []): self
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        if ($name === '') {
            throw InvalidDish::empty();
        }

        return new self($name, null, $ingredients);
    }

    /**
     * El mismo plato, apuntando a una receta del recetario.
     *
     * CONCEPTO: "wither" en un value object inmutable
     * No cambia este plato (es readonly): devuelve uno nuevo con la
     * referencia. Es el equivalente inmutable de un setter, y por eso se
     * llama with…(), como DateTimeImmutable::modify() devuelve otra fecha.
     */
    public function withRecipe(RecipeReference $recipe): self
    {
        return new self($this->name, $this->description, $this->ingredients, $recipe);
    }

    /**
     * El mismo plato, sin la receta, si apuntaba a esa (HEXM-0027).
     *
     * Regla 24: cuando se borra una receta del recetario, los platos que la
     * usaban se DESVINCULAN: conservan su nombre, su descripción y sus
     * ingredientes, y dejan de apuntar a ella. Un plato que apunta a otra
     * receta, o a ninguna, se devuelve tal cual (es el mismo objeto: no hay
     * nada que cambiar).
     */
    public function withoutRecipe(RecipeReference $recipe): self
    {
        if ($this->recipe === null || ! $this->recipe->equals($recipe)) {
            return $this;
        }

        return new self($this->name, $this->description, $this->ingredients);
    }

    /**
     * La receta a la que apunta el plato, o null si no tiene.
     */
    public function recipe(): ?RecipeReference
    {
        return $this->recipe;
    }

    /**
     * Nombre corto ("Pasta con salmón y pesto"), o null en la semana 1.
     */
    public function name(): ?string
    {
        return $this->name;
    }

    /**
     * Texto libre de la nutricionista, o null en la semana 2.
     */
    public function description(): ?string
    {
        return $this->description;
    }

    /**
     * @return list<DishIngredient>
     */
    public function ingredients(): array
    {
        return $this->ingredients;
    }

    /**
     * Igualdad por valor: mismo nombre, misma descripción, los mismos
     * ingredientes en el mismo orden y la misma receta (o ninguna).
     */
    public function equals(self $other): bool
    {
        if ($this->name !== $other->name
            || $this->description !== $other->description
            || count($this->ingredients) !== count($other->ingredients)
            || ! self::sameRecipe($this->recipe, $other->recipe)) {
            return false;
        }

        foreach ($this->ingredients as $position => $ingredient) {
            if (! $ingredient->equals($other->ingredients[$position])) {
                return false;
            }
        }

        return true;
    }

    private static function sameRecipe(?RecipeReference $a, ?RecipeReference $b): bool
    {
        return $a === null || $b === null ? $a === $b : $a->equals($b);
    }
}
