<?php

/*
| Tests del cableado del recetario (RecipesServiceProvider).
|
| CONCEPTO: probar la raíz de composición
| Los tests unitarios de CreateRecipe construyen el caso de uso a mano con
| el adaptador en memoria, así que nunca pasan por el contenedor de Laravel.
| Estos tests cubren justo lo que aquellos no ven: que, dentro de la
| aplicación real, el puerto está enchufado al adaptador correcto.
*/

use Illuminate\Foundation\Testing\RefreshDatabase;
use Recipes\Application\CreateRecipe;
use Recipes\Application\CreateRecipeCommand;
use Recipes\Domain\RecipeRepository;
use Recipes\Infrastructure\Persistence\Eloquent\EloquentRecipeRepository;

uses(RefreshDatabase::class);

it('binds the recipe repository port to the Eloquent adapter', function () {
    // Se pide la INTERFAZ y el contenedor devuelve la implementación
    // registrada en el binding.
    expect(app(RecipeRepository::class))->toBeInstanceOf(EloquentRecipeRepository::class);
});

it('builds the CreateRecipe use case with the Eloquent adapter', function () {
    // CONCEPTO: autowiring
    // Nadie ha registrado CreateRecipe: el contenedor lee su constructor,
    // ve que necesita un RecipeRepository y usa el binding. El repositorio
    // es privado, así que se comprueba por su efecto: la receta acaba en
    // PostgreSQL, no en memoria.
    $createRecipe = app(CreateRecipe::class);

    $id = $createRecipe(new CreateRecipeCommand(
        name: 'Pisto',
        servings: 2,
        ingredients: [['name' => 'Calabacín', 'amount' => '1', 'unit' => 'piece']],
    ));

    $this->assertDatabaseHas('recipes', ['id' => $id->value(), 'name' => 'Pisto']);
});
