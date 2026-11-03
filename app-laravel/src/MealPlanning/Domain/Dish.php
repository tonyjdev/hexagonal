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
 * Fuera de alcance (nivel 6): la referencia opcional a una receta del
 * recetario (RecipeId). Llegará con su propia validación entre contextos.
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
     * Igualdad por valor: mismo nombre, misma descripción y los mismos
     * ingredientes en el mismo orden.
     */
    public function equals(self $other): bool
    {
        if ($this->name !== $other->name
            || $this->description !== $other->description
            || count($this->ingredients) !== count($other->ingredients)) {
            return false;
        }

        foreach ($this->ingredients as $position => $ingredient) {
            if (! $ingredient->equals($other->ingredients[$position])) {
                return false;
            }
        }

        return true;
    }
}
