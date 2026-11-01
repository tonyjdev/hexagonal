<?php

/*
| Tests de POST /api/recipes: el adaptador driving de punta a punta.
|
| CONCEPTO: test de feature (end to end dentro de Laravel)
| Cada test hace una petición HTTP simulada ($this->postJson) que recorre
| TODA la aplicación: rutas → FormRequest → controlador → caso de uso →
| dominio → repositorio de Eloquent → PostgreSQL (hexagonal_test, ADR 0002).
| No se simula nada. Es la primera vez que el hexágono se prueba completo.
|
| Los tests siguen los tres caminos posibles de una petición, que son justo
| los criterios de aceptación de HEXM-0010:
|
|   1. receta válida              → 201 y la receta guardada;
|   2. incumple una regla         → 422 del DOMINIO ({"message"} en español);
|   3. petición mal formada       → 422 de la VALIDACIÓN de Laravel
|                                   ({"message", "errors"}), sin llegar al
|                                   caso de uso.
|
| La diferencia entre 2 y 3 es la del ejercicio del "recepcionista" y el
| "chef": la forma la comprueba la frontera; las reglas, el dominio.
*/

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Recipes\Domain\Recipe;
use Recipes\Domain\RecipeId;
use Recipes\Domain\RecipeRepository;
use Tests\Support\Recipes\RecipeMother;

uses(RefreshDatabase::class);

/**
 * Una petición válida (el pisto de los planes), con cambios opcionales.
 *
 * Cada test parte de una receta correcta y estropea UNA sola cosa: así, si
 * el test falla, se sabe exactamente qué dato lo ha provocado.
 *
 * Arr::set admite claves con puntos ("ingredients.0.amount") para cambiar
 * un valor anidado.
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function recipePayload(array $changes = []): array
{
    $payload = [
        'name' => 'Pisto',
        'servings' => 2,
        'ingredients' => [
            ['name' => 'Calabacín', 'amount' => '1', 'unit' => 'piece'],
            ['name' => 'Tomate triturado', 'amount' => '½', 'unit' => 'jar'],
            ['name' => 'Sal', 'amount' => null, 'unit' => null],
        ],
        'steps' => ['Picar las verduras', 'Pochar a fuego lento'],
    ];

    foreach ($changes as $key => $value) {
        Arr::set($payload, $key, $value);
    }

    return $payload;
}

// ---------------------------------------------------------------------------
// 1. Receta válida
// ---------------------------------------------------------------------------

it('creates a recipe and returns its id and location', function () {
    $response = $this->postJson('/api/recipes', recipePayload());

    $response->assertCreated()->assertJsonStructure(['id']);

    $id = $response->json('id');
    expect($id)->toBeString();

    // RecipeId::fromString valida que sea un UUID: si el id no lo fuera,
    // lanzaría InvalidRecipeId y el test fallaría aquí.
    $recipeId = RecipeId::fromString($id);
    $response->assertHeader('Location', url("/api/recipes/{$id}"));

    // Se comprueba lo guardado A TRAVÉS DEL PUERTO, no mirando las tablas:
    // lo que importa es que la receta que se recupera es la que se envió
    // (con ½ exacto y el ingrediente al gusto), no cómo se reparte en filas
    // (eso ya lo prueban los tests del repositorio de Eloquent).
    $saved = app(RecipeRepository::class)->find($recipeId);

    expect($saved)->toBeInstanceOf(Recipe::class);
    /** @var Recipe $saved */
    expect(RecipeMother::snapshot($saved))->toBe([
        'id' => $id,
        'name' => 'Pisto',
        'servings' => 2,
        'ingredients' => [
            ['name' => 'Calabacín', 'quantity' => '1/1 piece'],
            ['name' => 'Tomate triturado', 'quantity' => '1/2 jar'],
            ['name' => 'Sal', 'quantity' => null],
        ],
        'steps' => ['Picar las verduras', 'Pochar a fuego lento'],
    ]);
});

it('creates a recipe without steps, because steps are optional', function () {
    // Regla de negocio 12: algunas recetas de los planes solo traen
    // ingredientes y raciones. El campo steps puede no venir.
    $payload = Arr::except(recipePayload(), 'steps');

    $this->postJson('/api/recipes', $payload)->assertCreated();

    $this->assertDatabaseCount('recipes', 1);
    $this->assertDatabaseCount('recipe_steps', 0);
});

// ---------------------------------------------------------------------------
// 2. Reglas de negocio: las rechaza el dominio
// ---------------------------------------------------------------------------

it('rejects a recipe that breaks a business rule with the domain message', function (array $payload, string $message) {
    $this->postJson('/api/recipes', $payload)
        ->assertUnprocessable()
        // assertExactJson: SOLO "message", sin "errors". Es la huella de que
        // la petición pasó la validación de forma y la paró el dominio.
        ->assertExactJson(['message' => $message]);

    // Y no se guarda nada: el caso de uso solo llama a save() con una
    // receta ya creada, es decir, válida.
    $this->assertDatabaseCount('recipes', 0);
})->with([
    'without ingredients' => [
        recipePayload(['ingredients' => []]),
        'Una receta debe tener al menos un ingrediente.',
    ],
    'with a zero quantity' => [
        recipePayload(['ingredients.0.amount' => '0']),
        'La cantidad debe ser mayor que cero.',
    ],
    'with a repeated ingredient' => [
        recipePayload(['ingredients.1' => ['name' => 'Calabacín', 'amount' => '2', 'unit' => 'piece']]),
        'El ingrediente «Calabacín» está repetido.',
    ],
    'with zero servings' => [
        recipePayload(['servings' => 0]),
        'Una receta debe tener al menos una ración.',
    ],
    'with an empty name' => [
        // "" lo convierte Laravel en null (ConvertEmptyStringsToNull) y
        // CreateRecipeRequest lo devuelve a "", para que decida el dominio.
        recipePayload(['name' => '']),
        'Una receta debe tener nombre.',
    ],
    'with an empty step' => [
        recipePayload(['steps' => ['Picar las verduras', '']]),
        'Un paso de preparación no puede estar vacío.',
    ],
    'with an unknown unit' => [
        // La lista de unidades es del dominio (enum Unit), no de la
        // validación: por eso no hay una regla `in:` en la FormRequest.
        recipePayload(['ingredients.0.unit' => 'handful']),
        'La unidad "handful" no existe.',
    ],
    'with an amount but no unit' => [
        recipePayload(['ingredients.0.unit' => null]),
        'El ingrediente «Calabacín» debe indicar la cantidad y la unidad, o ninguna de las dos si es al gusto.',
    ],
]);

// ---------------------------------------------------------------------------
// 3. Peticiones mal formadas: las rechaza la validación de Laravel
// ---------------------------------------------------------------------------

it('rejects a malformed request with validation errors before reaching the use case', function (array $payload, string $field) {
    $this->postJson('/api/recipes', $payload)
        ->assertUnprocessable()
        // assertJsonValidationErrors exige la clave "errors" con el campo
        // indicado. Esa respuesta la genera la FormRequest ANTES de ejecutar
        // el controlador: si la validación falla, Laravel responde por su
        // cuenta y ni el controlador ni el caso de uso llegan a ejecutarse.
        ->assertJsonValidationErrors([$field]);

    $this->assertDatabaseCount('recipes', 0);
})->with([
    'without name' => [Arr::except(recipePayload(), 'name'), 'name'],
    'without servings' => [Arr::except(recipePayload(), 'servings'), 'servings'],
    'with servings as text' => [recipePayload(['servings' => 'dos']), 'servings'],
    'without ingredients' => [Arr::except(recipePayload(), 'ingredients'), 'ingredients'],
    // Un objeto JSON ({"a": ...}) llega como array asociativo: no es una lista.
    'with ingredients as an object' => [
        recipePayload(['ingredients' => ['first' => ['name' => 'Sal', 'amount' => null, 'unit' => null]]]),
        'ingredients',
    ],
    'with an ingredient without name' => [
        recipePayload(['ingredients.0' => ['amount' => '1', 'unit' => 'piece']]),
        'ingredients.0.name',
    ],
    // El contrato dice que amount es texto ("½", "1,5"): un número no vale.
    'with an amount as a number' => [recipePayload(['ingredients.0.amount' => 200]), 'ingredients.0.amount'],
    'with steps as text' => [recipePayload(['steps' => 'Picar las verduras']), 'steps'],
]);
