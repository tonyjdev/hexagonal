<?php

/*
| Tests del caso de uso CreateRecipe.
|
| CONCEPTO: test de caso de uso con un adaptador en memoria
| El caso de uso recibe el adaptador en memoria a través del puerto
| RecipeRepository. No arranca Laravel ni PostgreSQL: el núcleo completo
| (aplicación + dominio) se prueba en milisegundos. Se comprueba el
| RESULTADO (la receta guardada), no las llamadas internas (como haría un
| mock): ver InMemoryRecipeRepository.
*/

use Recipes\Application\CreateRecipe;
use Recipes\Application\CreateRecipeCommand;
use Recipes\Domain\Ingredient;
use Recipes\Domain\InvalidIngredient;
use Recipes\Domain\InvalidQuantity;
use Recipes\Domain\InvalidRecipe;
use Recipes\Domain\InvalidServings;
use Recipes\Domain\InvalidStep;
use Recipes\Domain\Quantity;
use Recipes\Domain\RecipeId;
use Recipes\Domain\Unit;
use Recipes\Infrastructure\InMemoryRecipeRepository;
use Shared\Domain\DomainException;

/**
 * Un comando válido de ejemplo (datos ficticios inspirados en la semana 1):
 * lentejas con pisto. Cada test cambia solo lo que necesita.
 *
 * @param  list<array{name: string, amount: string|null, unit: string|null}>|null  $ingredients
 * @param  list<string>  $steps
 */
function lentilsCommand(
    string $name = 'Lentejas con pisto',
    int $servings = 2,
    ?array $ingredients = null,
    array $steps = ['Calentar el pisto', 'Añadir las lentejas escurridas'],
): CreateRecipeCommand {
    return new CreateRecipeCommand($name, $servings, $ingredients ?? [
        ['name' => 'Lentejas cocidas', 'amount' => '½', 'unit' => 'jar'],
        ['name' => 'Pisto', 'amount' => '150', 'unit' => 'gram'],
        ['name' => 'Sal', 'amount' => null, 'unit' => null],
    ], $steps);
}

beforeEach(function () {
    $this->recipes = new InMemoryRecipeRepository;
    $this->createRecipe = new CreateRecipe($this->recipes);
});

it('creates and saves a recipe, and returns its id', function () {
    $id = ($this->createRecipe)(lentilsCommand());

    $recipe = $this->recipes->find($id);

    expect($recipe)->not->toBeNull()
        ->and($recipe->id()->equals($id))->toBeTrue()
        ->and($recipe->name())->toBe('Lentejas con pisto')
        ->and($recipe->servings()->value())->toBe(2)
        ->and(array_map(fn ($step) => $step->text(), $recipe->steps()))
        ->toBe(['Calentar el pisto', 'Añadir las lentejas escurridas']);
});

it('translates the primitive ingredients into domain objects', function () {
    $recipe = $this->recipes->find(($this->createRecipe)(lentilsCommand()));
    $ingredients = $recipe?->ingredients() ?? [];

    // Lo guardado es igual (por valor) a los ingredientes construidos a mano.
    expect($ingredients)->toHaveCount(3)
        ->and($ingredients[0]->equals(Ingredient::of('Lentejas cocidas', Quantity::fraction(1, 2, Unit::Jar))))->toBeTrue()
        ->and($ingredients[1]->equals(Ingredient::of('Pisto', Quantity::of(150, Unit::Gram))))->toBeTrue()
        ->and($ingredients[2]->isToTaste())->toBeTrue();
});

it('generates a new id for every recipe', function () {
    $first = ($this->createRecipe)(lentilsCommand());
    $second = ($this->createRecipe)(lentilsCommand());

    expect($first->equals($second))->toBeFalse()
        ->and($this->recipes->count())->toBe(2);
});

it('can create a recipe without steps', function () {
    $id = ($this->createRecipe)(lentilsCommand(steps: []));

    expect($this->recipes->find($id)?->steps())->toBe([]);
});

describe('invalid data', function () {
    it('saves nothing and lets the domain exception through', function (Closure $command, string $exception, string $message) {
        // El caso de uso no captura ni traduce las excepciones de dominio:
        // las deja subir para que el adaptador de entrada decida cómo
        // mostrarlas (en el nivel 2, un HTTP 422).
        expect(fn () => ($this->createRecipe)($command()))->toThrow($exception, $message)
            ->and($this->recipes->count())->toBe(0);
    })->with([
        'sin ingredientes' => [
            fn () => lentilsCommand(ingredients: []),
            InvalidRecipe::class, 'Una receta debe tener al menos un ingrediente.',
        ],
        'cero raciones' => [
            fn () => lentilsCommand(servings: 0),
            InvalidServings::class, 'Una receta debe tener al menos una ración.',
        ],
        'sin nombre' => [
            fn () => lentilsCommand(name: ' '),
            InvalidRecipe::class, 'Una receta debe tener nombre.',
        ],
        'ingrediente repetido' => [
            fn () => lentilsCommand(ingredients: [
                ['name' => 'Cebolla', 'amount' => '1', 'unit' => 'piece'],
                ['name' => 'cebolla', 'amount' => null, 'unit' => null],
            ]),
            InvalidRecipe::class, 'El ingrediente «cebolla» está repetido.',
        ],
        'cantidad cero' => [
            fn () => lentilsCommand(ingredients: [['name' => 'Arroz', 'amount' => '0', 'unit' => 'gram']]),
            InvalidQuantity::class, 'La cantidad debe ser mayor que cero.',
        ],
        'unidad desconocida' => [
            fn () => lentilsCommand(ingredients: [['name' => 'Arroz', 'amount' => '200', 'unit' => 'gramo']]),
            InvalidIngredient::class, 'La unidad "gramo" no existe.',
        ],
        'cantidad sin unidad' => [
            fn () => lentilsCommand(ingredients: [['name' => 'Arroz', 'amount' => '200', 'unit' => null]]),
            InvalidIngredient::class, 'El ingrediente «Arroz» debe indicar la cantidad y la unidad',
        ],
        'unidad sin cantidad' => [
            fn () => lentilsCommand(ingredients: [['name' => 'Arroz', 'amount' => null, 'unit' => 'gram']]),
            InvalidIngredient::class, 'El ingrediente «Arroz» debe indicar la cantidad y la unidad',
        ],
        'paso vacío' => [
            fn () => lentilsCommand(steps: ['Calentar el pisto', '  ']),
            InvalidStep::class, 'Un paso de preparación no puede estar vacío.',
        ],
    ]);

    it('only raises domain exceptions, with messages for the user', function () {
        // Ni ValueError (Unit::from) ni errores técnicos: todo lo que puede
        // fallar por culpa de los datos es un DomainException.
        expect(fn () => ($this->createRecipe)(lentilsCommand(ingredients: [
            ['name' => 'Arroz', 'amount' => '200', 'unit' => 'kilo'],
        ])))->toThrow(DomainException::class);
    });
});

it('finds nothing for an id that was never created', function () {
    expect($this->recipes->find(RecipeId::generate()))->toBeNull();
});
