<?php

/*
| Tests de GET /api/recipes/{id}: la primera consulta, de punta a punta.
|
| Recorren toda la aplicación contra PostgreSQL (hexagonal_test):
| ruta → GetRecipeController → GetRecipe → RecipeRepository (Eloquent) →
| RecipeView → RecipeResource → JSON.
|
| Los tres resultados posibles de la consulta:
|   1. la receta existe          → 200 con sus datos legibles;
|   2. no existe                 → 404 (RecipeNotFound, un resultado normal);
|   3. el id no puede existir    → 422 (InvalidRecipeId, decisión de HEXM-0011).
*/

use Illuminate\Foundation\Testing\RefreshDatabase;
use Recipes\Domain\RecipeRepository;
use Tests\Support\Recipes\RecipeMother;

uses(RefreshDatabase::class);

/**
 * La receta del ejemplo de la API: el pisto, con una cantidad entera, una
 * fracción Unicode, un decimal ("1,5") y un ingrediente al gusto.
 *
 * @return array<string, mixed>
 */
function pistoPayload(): array
{
    return [
        'name' => 'Pisto',
        'servings' => 2,
        'ingredients' => [
            ['name' => 'Calabacín', 'amount' => '1', 'unit' => 'piece'],
            ['name' => 'Tomate triturado', 'amount' => '½', 'unit' => 'jar'],
            ['name' => 'Aceite de oliva', 'amount' => '1,5', 'unit' => 'tablespoon'],
            ['name' => 'Sal', 'amount' => null, 'unit' => null],
        ],
        'steps' => ['Picar las verduras', 'Pochar a fuego lento'],
    ];
}

// ---------------------------------------------------------------------------
// 1. La receta existe → 200
// ---------------------------------------------------------------------------

it('returns a recipe created with POST, with exact and readable amounts', function () {
    // Ida y vuelta por la API: lo que se crea con POST se lee con GET.
    $id = $this->postJson('/api/recipes', pistoPayload())->assertCreated()->json('id');
    expect($id)->toBeString();

    $this->getJson("/api/recipes/{$id}")
        ->assertOk()
        // assertExactJson: el contrato completo de la respuesta, sin claves
        // de más ni de menos. Es la documentación ejecutable de la API.
        ->assertExactJson([
            'data' => [
                'id' => $id,
                'name' => 'Pisto',
                'servings' => 2,
                'ingredients' => [
                    ['name' => 'Calabacín', 'amount' => '1', 'unit' => 'piece', 'unit_label' => 'unidad', 'to_taste' => false],
                    // ½ sigue siendo ½: se guardó exacto (1/2) y se escribe igual.
                    ['name' => 'Tomate triturado', 'amount' => '½', 'unit' => 'jar', 'unit_label' => 'bote', 'to_taste' => false],
                    // "1,5" se guardó como 3/2 y vuelve como en los planes: "1 ½".
                    ['name' => 'Aceite de oliva', 'amount' => '1 ½', 'unit' => 'tablespoon', 'unit_label' => 'cucharada sopera', 'to_taste' => false],
                    // "Al gusto" se distingue explícitamente.
                    ['name' => 'Sal', 'amount' => null, 'unit' => null, 'unit_label' => null, 'to_taste' => true],
                ],
                'steps' => ['Picar las verduras', 'Pochar a fuego lento'],
            ],
        ]);
});

it('serves the recipe at the URL given in the Location header of POST', function () {
    // La cabecera Location (generada con route('recipes.show')) lleva de
    // verdad a la receta recién creada.
    $response = $this->postJson('/api/recipes', pistoPayload());
    $location = $response->headers->get('Location');
    expect($location)->toBeString();
    /** @var string $location */
    $this->getJson($location)
        ->assertOk()
        ->assertJsonPath('data.id', $response->json('id'))
        ->assertJsonPath('data.name', 'Pisto');
});

it('returns a recipe saved through the repository port', function () {
    // Sin pasar por el POST: la receta se guarda directamente con el puerto
    // (el adaptador de Eloquent, por el binding). Así el GET no depende de
    // que el alta funcione.
    $recipe = RecipeMother::lentilsWithPisto();
    app(RecipeRepository::class)->save($recipe);

    $this->getJson("/api/recipes/{$recipe->id()->value()}")
        ->assertOk()
        ->assertJsonPath('data.name', 'Lentejas con pisto')
        ->assertJsonPath('data.ingredients.0.amount', '½')
        ->assertJsonPath('data.ingredients.2.amount', '1 ½')
        ->assertJsonPath('data.ingredients.3.to_taste', true)
        ->assertJsonCount(3, 'data.steps');
});

it('accepts the id in upper case, because the domain normalizes it', function () {
    // RecipeId::fromString pasa el id a minúsculas: la misma receta se
    // encuentra se escriba como se escriba.
    $recipe = RecipeMother::chickpeaSalad();
    app(RecipeRepository::class)->save($recipe);

    $this->getJson('/api/recipes/'.strtoupper($recipe->id()->value()))
        ->assertOk()
        ->assertJsonPath('data.id', $recipe->id()->value());
});

// ---------------------------------------------------------------------------
// 2. No existe → 404
// ---------------------------------------------------------------------------

it('responds 404 with a message in Spanish when the recipe does not exist', function () {
    $this->getJson('/api/recipes/01a19699-76e7-7a25-96dc-da0375dce729')
        ->assertNotFound()
        ->assertExactJson(['message' => 'No existe ninguna receta con el identificador «01a19699-76e7-7a25-96dc-da0375dce729».']);
});

// ---------------------------------------------------------------------------
// 3. El id no puede existir → 422
// ---------------------------------------------------------------------------

it('responds 422 with the domain message when the id is malformed', function () {
    // Decisión de HEXM-0011: "abc" no puede ser una receta (422), que no es
    // lo mismo que una receta que no existe (404). El mensaje es el del
    // dominio (InvalidRecipeId), traducido por el manejador global.
    $this->getJson('/api/recipes/abc')
        ->assertUnprocessable()
        ->assertExactJson(['message' => '"abc" no es un identificador de receta válido.']);
});
