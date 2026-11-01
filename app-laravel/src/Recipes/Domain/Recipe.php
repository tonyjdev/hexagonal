<?php

declare(strict_types=1);

namespace Recipes\Domain;

/**
 * Recipe: una receta del recetario.
 *
 * CONCEPTO: Entidad (frente a Value Object)
 * Una receta tiene IDENTIDAD (su RecipeId) y CICLO DE VIDA: nace, se le
 * cambia el nombre, más adelante se le cambiarán los ingredientes o se
 * borrará... y durante todo ese tiempo sigue siendo "la misma receta". Por
 * eso equals() compara solo el identificador, no los datos: "Crema de
 * calabaza" renombrada a "Crema de calabaza asada" es la misma receta.
 * Un value object (Ingredient, Quantity) es lo contrario: no tiene identidad
 * y dos con los mismos datos son iguales.
 *
 * CONCEPTO: Agregado y raíz de agregado
 * Una receta y sus ingredientes y pasos forman un AGREGADO: un grupo de
 * objetos que se guarda, se carga y se valida como una unidad. Recipe es su
 * RAÍZ, la única puerta de entrada: nadie de fuera puede añadir un
 * ingrediente "por detrás" de la receta, porque entonces se saltaría la regla
 * de que no haya ingredientes repetidos. El agregado es la FRONTERA DE
 * CONSISTENCIA: dentro de él, las reglas se cumplen siempre. El repositorio
 * (HEXM-0007) guardará y cargará la receta entera, nunca un ingrediente
 * suelto.
 *
 * CONCEPTO: invariantes (always-valid domain model)
 * Reglas que una receta cumple SIEMPRE, desde que se crea hasta que
 * desaparece (ver "Reglas de negocio" en docs/DOMINIO.md):
 *   1. tiene nombre (no vacío);
 *   2. tiene al menos un ingrediente;
 *   3. no repite un ingrediente (mismo nombre, sin distinguir mayúsculas);
 *   4. tiene al menos una ración (lo garantiza el tipo Servings).
 * Los pasos son OPCIONALES: algunas recetas de los planes solo detallan
 * ingredientes y raciones (el pisto de la semana 1).
 * Toda forma de crear o modificar la receta pasa por estas comprobaciones.
 *
 * CONCEPTO: modelo rico frente a modelo anémico
 * En un modelo ANÉMICO (el típico modelo de Eloquent usado "a pelo") la
 * entidad es una bolsa de datos con setters ($recipe->name = '') y las reglas
 * viven repartidas en controladores y FormRequests. Aquí el modelo es RICO:
 * no hay setters, sino métodos con significado de negocio (rename()) que
 * validan antes de cambiar nada. Solo existe el comportamiento que alguien
 * necesita: hoy, crear y renombrar.
 *
 * CONCEPTO: colecciones inmutables con arrays de PHP
 * No usamos Illuminate\Support\Collection (el dominio no conoce Laravel).
 * Los arrays de PHP son TIPOS POR VALOR: al devolverlos o recibirlos se
 * copian (copy-on-write: la copia real solo ocurre si alguien la modifica).
 * Así, quien haga $list = $recipe->ingredients(); $list[] = ... modifica SU
 * copia, no la de la receta. Además, las propiedades son readonly (nadie, ni
 * la propia clase, puede reasignarlas) y lo que contienen son value objects
 * inmutables. Resultado: el estado interno no se puede tocar desde fuera.
 * Los tipos list<Ingredient> de los docblocks los comprueba PHPStan: PHP no
 * tiene genéricos, pero el análisis estático sí los entiende.
 *
 * CAPA: Dominio (Recipes\Domain).
 */
final class Recipe
{
    /**
     * Privado: solo se crea con Recipe::create(), que comprueba las
     * invariantes.
     *
     * Solo $name no es readonly: es lo único que hoy puede cambiar (rename()).
     *
     * @param  list<Ingredient>  $ingredients
     * @param  list<Step>  $steps
     */
    private function __construct(
        private readonly RecipeId $id,
        private string $name,
        private readonly Servings $servings,
        private readonly array $ingredients,
        private readonly array $steps,
    ) {}

    /**
     * Crea una receta nueva comprobando todas sus invariantes.
     *
     * Los pasos se reciben en orden: su posición en la lista ES su número de
     * paso (por eso Step no guarda su número, ver Step).
     *
     * @param  array<Ingredient>  $ingredients
     * @param  array<Step>  $steps
     */
    public static function create(
        RecipeId $id,
        string $name,
        Servings $servings,
        array $ingredients,
        array $steps = [],
    ): self {
        $name = self::validName($name);

        // array_values: si llega un array con claves raras (['a' => ...]),
        // nos quedamos con una lista 0, 1, 2... en el mismo orden.
        $ingredients = array_values($ingredients);

        self::guardAgainstMissingIngredients($ingredients);
        self::guardAgainstDuplicatedIngredients($ingredients);

        return new self($id, $name, $servings, $ingredients, array_values($steps));
    }

    /**
     * Cambia el nombre de la receta. Sigue siendo la misma receta (mismo
     * RecipeId): eso es el ciclo de vida de una entidad.
     */
    public function rename(string $name): void
    {
        $this->name = self::validName($name);
    }

    public function id(): RecipeId
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function servings(): Servings
    {
        return $this->servings;
    }

    /**
     * Devuelve una COPIA de la lista (los arrays se copian por valor):
     * modificarla no cambia la receta.
     *
     * @return list<Ingredient>
     */
    public function ingredients(): array
    {
        return $this->ingredients;
    }

    /**
     * @return list<Step>
     */
    public function steps(): array
    {
        return $this->steps;
    }

    /**
     * Igualdad por IDENTIDAD: dos objetos Recipe son la misma receta si
     * tienen el mismo RecipeId, aunque su nombre o sus datos sean distintos
     * (por ejemplo, la versión de antes y de después de renombrarla).
     */
    public function equals(self $other): bool
    {
        return $this->id->equals($other->id);
    }

    private static function validName(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            throw InvalidRecipe::emptyName();
        }

        return $name;
    }

    /**
     * @param  list<Ingredient>  $ingredients
     */
    private static function guardAgainstMissingIngredients(array $ingredients): void
    {
        if ($ingredients === []) {
            throw InvalidRecipe::withoutIngredients();
        }
    }

    /**
     * Compara cada ingrediente con los anteriores. Es O(n²), pero una receta
     * tiene una decena de ingredientes: la claridad gana a la eficiencia.
     *
     * @param  list<Ingredient>  $ingredients
     */
    private static function guardAgainstDuplicatedIngredients(array $ingredients): void
    {
        foreach ($ingredients as $position => $ingredient) {
            foreach (array_slice($ingredients, 0, $position) as $previous) {
                if ($ingredient->hasSameNameAs($previous)) {
                    throw InvalidRecipe::duplicatedIngredient($ingredient->name());
                }
            }
        }
    }
}
