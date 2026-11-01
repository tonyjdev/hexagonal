<?php

/*
| Tests de Recipe: la entidad raíz del agregado del recetario.
|
| Cubren las invariantes (una receta inválida no puede existir), la identidad
| (se compara por RecipeId, no por datos) y la protección del estado interno
| (sus listas no se pueden modificar desde fuera).
*/

use Recipes\Domain\Ingredient;
use Recipes\Domain\InvalidRecipe;
use Recipes\Domain\Quantity;
use Recipes\Domain\Recipe;
use Recipes\Domain\RecipeId;
use Recipes\Domain\Servings;
use Recipes\Domain\Step;
use Recipes\Domain\Unit;
use Shared\Domain\DomainException;

/**
 * Una receta válida de ejemplo (datos ficticios, inspirados en los planes):
 * lentejas con pisto para 2 raciones. Cada test cambia solo lo que necesita.
 *
 * @param  array<Ingredient>|null  $ingredients
 * @param  array<Step>  $steps
 */
function lentilsWithPisto(
    ?RecipeId $id = null,
    string $name = 'Lentejas con pisto',
    ?array $ingredients = null,
    array $steps = [],
): Recipe {
    return Recipe::create(
        $id ?? RecipeId::generate(),
        $name,
        Servings::of(2),
        $ingredients ?? [
            Ingredient::of('Lentejas cocidas', Quantity::fromString('½', Unit::Jar)),
            Ingredient::of('Pisto', Quantity::of(150, Unit::Gram)),
            Ingredient::toTaste('Sal'),
        ],
        $steps,
    );
}

describe('creation', function () {
    it('keeps its data', function () {
        $id = RecipeId::generate();
        $recipe = lentilsWithPisto(id: $id, steps: [
            Step::fromString('Calentar el pisto'),
            Step::fromString('Añadir las lentejas escurridas'),
        ]);

        expect($recipe->id()->equals($id))->toBeTrue()
            ->and($recipe->name())->toBe('Lentejas con pisto')
            ->and($recipe->servings()->value())->toBe(2)
            ->and($recipe->ingredients())->toHaveCount(3)
            ->and($recipe->ingredients()[0]->name())->toBe('Lentejas cocidas');
    });

    it('keeps the steps in order: the position is the step number', function () {
        $recipe = lentilsWithPisto(steps: [
            Step::fromString('Calentar el pisto'),
            Step::fromString('Añadir las lentejas escurridas'),
        ]);

        expect(array_map(fn (Step $step) => $step->text(), $recipe->steps()))
            ->toBe(['Calentar el pisto', 'Añadir las lentejas escurridas']);
    });

    it('can have no steps', function () {
        // Decisión de negocio: los pasos son opcionales (algunas recetas de
        // los planes solo detallan ingredientes y raciones).
        expect(lentilsWithPisto(steps: [])->steps())->toBe([]);
    });

    it('trims the name', function () {
        expect(lentilsWithPisto(name: '  Lentejas con pisto ')->name())->toBe('Lentejas con pisto');
    });
});

describe('invariants', function () {
    it('must have a name', function (string $name) {
        lentilsWithPisto(name: $name);
    })->with(['', '   '])->throws(InvalidRecipe::class, 'Una receta debe tener nombre.');

    it('must have at least one ingredient', function () {
        lentilsWithPisto(ingredients: []);
    })->throws(InvalidRecipe::class, 'Una receta debe tener al menos un ingrediente.');

    it('cannot repeat an ingredient, ignoring case and quantity', function () {
        lentilsWithPisto(ingredients: [
            Ingredient::of('Cebolla', Quantity::of(1, Unit::Piece)),
            Ingredient::of('Pimiento', Quantity::of(1, Unit::Piece)),
            Ingredient::of('cebolla', Quantity::fromString('½', Unit::Piece)),
        ]);
    })->throws(InvalidRecipe::class, 'El ingrediente «cebolla» está repetido.');

    it('cannot be renamed to an empty name', function () {
        $recipe = lentilsWithPisto();

        expect(fn () => $recipe->rename('  '))->toThrow(InvalidRecipe::class, 'Una receta debe tener nombre.')
            // Y la receta sigue como estaba: un cambio inválido no la deja a medias.
            ->and($recipe->name())->toBe('Lentejas con pisto');
    });

    it('raises domain exceptions', function () {
        expect(fn () => lentilsWithPisto(ingredients: []))->toThrow(DomainException::class);
    });
});

describe('identity', function () {
    it('is the same recipe after being renamed', function () {
        // CONCEPTO: entidad. Cambian sus datos, pero no su identidad.
        $recipe = lentilsWithPisto();
        $before = lentilsWithPisto(id: $recipe->id());

        $recipe->rename('Lentejas con pisto y huevo');

        expect($recipe->name())->toBe('Lentejas con pisto y huevo')
            ->and($recipe->equals($before))->toBeTrue();
    });

    it('is a different recipe if the id is different, even with the same data', function () {
        // Justo lo contrario que un value object (ver IngredientTest): dos
        // recetas idénticas en sus datos, pero con distinto id, son DOS recetas.
        expect(lentilsWithPisto()->equals(lentilsWithPisto()))->toBeFalse();
    });
});

describe('protected internal state', function () {
    it('cannot be modified through the list it returns', function () {
        $recipe = lentilsWithPisto();

        // Los arrays de PHP se copian por valor: $ingredients es una copia.
        $ingredients = $recipe->ingredients();
        $ingredients[] = Ingredient::toTaste('Sal');
        $ingredients[0] = Ingredient::toTaste('Otra cosa');

        expect($recipe->ingredients())->toHaveCount(3)
            ->and($recipe->ingredients()[0]->name())->toBe('Lentejas cocidas');
    });

    it('cannot be modified through the list it was created with', function () {
        $steps = [Step::fromString('Calentar el pisto')];
        $recipe = lentilsWithPisto(steps: $steps);

        $steps[] = Step::fromString('Paso añadido después');

        expect($recipe->steps())->toHaveCount(1);
    });

    it('cannot reassign its collections, not even from inside', function () {
        // readonly: una vez asignada, la propiedad no se puede volver a
        // asignar. Lo comprobamos "forzando" la entrada con una closure
        // enlazada a la clase (Closure::bind), que sí ve lo privado.
        $recipe = lentilsWithPisto();
        $overwrite = Closure::bind(fn () => $this->ingredients = [], $recipe, Recipe::class);

        expect($overwrite)->toThrow(Error::class, 'Cannot modify readonly property');
    });

    it('normalizes non-list arrays to ordered lists', function () {
        $recipe = lentilsWithPisto(ingredients: [
            'primero' => Ingredient::toTaste('Sal'),
            'segundo' => Ingredient::toTaste('Pimienta'),
        ]);

        expect(array_keys($recipe->ingredients()))->toBe([0, 1]);
    });
});
