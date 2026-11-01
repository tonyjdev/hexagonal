<?php

declare(strict_types=1);

namespace Recipes\Application;

use Recipes\Domain\Ingredient;
use Recipes\Domain\InvalidIngredient;
use Recipes\Domain\Quantity;
use Recipes\Domain\Recipe;
use Recipes\Domain\RecipeId;
use Recipes\Domain\RecipeRepository;
use Recipes\Domain\Servings;
use Recipes\Domain\Step;
use Recipes\Domain\Unit;

/**
 * CreateRecipe: caso de uso "dar de alta una receta en el recetario".
 *
 * CONCEPTO: Caso de uso (application service)
 * Orquesta, pero NO DECIDE. Sus pasos son siempre los mismos:
 *   1. traducir los datos de entrada (primitivos) a objetos del dominio;
 *   2. pedir al dominio que haga su trabajo (Recipe::create() comprueba las
 *      invariantes: aquí no hay ni un `if` de reglas de negocio);
 *   3. usar los puertos para lo que el dominio no hace (guardar);
 *   4. devolver lo que necesite quien lo llamó (el RecipeId).
 * Si mañana cambia la regla "al menos un ingrediente", se toca Recipe, no
 * este fichero. En un Laravel clásico este código estaría repartido entre el
 * controlador, el FormRequest y el modelo; aquí cualquier adaptador de
 * entrada (HTTP, Artisan, un test) reutiliza el mismo caso de uso.
 *
 * CONCEPTO: inyección de dependencias por constructor
 * Recibe el puerto RecipeRepository (la interfaz) en el constructor, nunca
 * una implementación concreta. En los tests le pasamos el adaptador en
 * memoria; en el nivel 2, el contenedor de Laravel le inyectará el de Eloquent
 * gracias a un binding en un service provider. Este código no cambiará.
 *
 * CONCEPTO: clase invocable (__invoke)
 * Un caso de uso hace UNA cosa, así que tiene un único método público. Con
 * __invoke el objeto se usa como una función: $createRecipe($command).
 *
 * DECISIÓN: el id lo genera el dominio (RecipeId::generate()), no el
 * repositorio con un nextIdentity(). Ver ADR 0005.
 *
 * Errores: si los datos no son válidos, la excepción de dominio sube tal cual
 * y no se guarda nada, porque save() solo se llama con una receta ya creada,
 * es decir, válida.
 *
 * CAPA: Aplicación (Recipes\Application). Solo depende de su dominio.
 */
final readonly class CreateRecipe
{
    public function __construct(private RecipeRepository $recipes) {}

    public function __invoke(CreateRecipeCommand $command): RecipeId
    {
        $recipe = Recipe::create(
            RecipeId::generate(),
            $command->name,
            Servings::of($command->servings),
            array_map($this->toIngredient(...), $command->ingredients),
            array_map(Step::fromString(...), $command->steps),
        );

        $this->recipes->save($recipe);

        return $recipe->id();
    }

    /**
     * Traduce un ingrediente en primitivos a un Ingredient del dominio.
     *
     * Esto es TRADUCCIÓN, no reglas de negocio: qué es una cantidad válida o
     * un nombre válido lo siguen decidiendo Quantity e Ingredient.
     *
     * CONCEPTO: first-class callable syntax
     * $this->toIngredient(...) (PHP 8.1) convierte el método en una Closure
     * para pasárselo a array_map, sin escribir fn ($i) => $this->toIngredient($i).
     *
     * @param  array{name: string, amount: string|null, unit: string|null}  $data
     */
    private function toIngredient(array $data): Ingredient
    {
        ['name' => $name, 'amount' => $amount, 'unit' => $unit] = $data;

        if ($amount === null && $unit === null) {
            return Ingredient::toTaste($name);
        }

        if ($amount === null || $unit === null) {
            throw InvalidIngredient::incompleteQuantity($name);
        }

        // Unit::tryFrom devuelve null si el valor no existe; Unit::from
        // lanzaría un ValueError técnico (en inglés). Lo convertimos en un
        // error de dominio con su mensaje en español.
        $knownUnit = Unit::tryFrom($unit) ?? throw InvalidIngredient::unknownUnit($unit);

        return Ingredient::of($name, Quantity::fromString($amount, $knownUnit));
    }
}
