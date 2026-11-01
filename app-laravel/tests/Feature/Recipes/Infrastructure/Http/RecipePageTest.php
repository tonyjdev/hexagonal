<?php

/*
| Tests de GET /recipes/{id}: la página web de una receta (HEXM-0016).
|
| Recorren toda la aplicación contra PostgreSQL: ruta web →
| RecipePageController → GetRecipe → RecipeRepository (Eloquent) →
| RecipeView → vista Blade recipes::show → HTML.
|
| CONCEPTO: probar un adaptador de presentación
| No se compara el HTML entero (cualquier cambio de estilo rompería el
| test), sino lo que importa al usuario: que se VEAN los datos, en orden
| (assertSeeInOrder), y que los errores lleven a la página adecuada.
*/

use Illuminate\Foundation\Testing\RefreshDatabase;
use Recipes\Domain\RecipeRepository;
use Tests\Support\Recipes\RecipeMother;

uses(RefreshDatabase::class);

it('shows a recipe with exact amounts, to-taste ingredients and ordered steps', function () {
    $recipe = RecipeMother::lentilsWithPisto();
    app(RecipeRepository::class)->save($recipe);

    $this->get(route('recipes.page', ['id' => $recipe->id()->value()]))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertSeeText('Lentejas con pisto')
        ->assertSeeText('Raciones: 2')
        // Cada ingrediente con su cantidad legible y su unidad, en el orden
        // de la receta: ½ y 1 ½ los escribió el dominio (amountAsText()).
        ->assertSeeTextInOrder([
            'Lentejas cocidas', '½', 'bote',
            'Pisto', '200', 'gramo',
            'Aceite de oliva', '1 ½', 'cucharada sopera',
            'Sal', 'al gusto',
        ])
        // Los pasos, en orden de preparación.
        ->assertSeeTextInOrder([
            'Calentar el pisto en una sartén',
            'Añadir las lentejas escurridas',
            'Cocinar 5 minutos y salar al gusto',
        ]);
});

it('shows the same recipe that the API creates, because both use GetRecipe', function () {
    // CONCEPTO: dos adaptadores, un caso de uso
    // Se crea con la API (JSON) y se lee con la web (HTML): los dos canales
    // comparten el núcleo, así que lo que entra por uno sale por el otro.
    $id = $this->postJson('/api/recipes', [
        'name' => 'Pisto',
        'servings' => 2,
        'ingredients' => [
            ['name' => 'Tomate triturado', 'amount' => '½', 'unit' => 'jar'],
            ['name' => 'Aceite de oliva', 'amount' => '1,5', 'unit' => 'tablespoon'],
        ],
    ])->assertCreated()->json('id');
    expect($id)->toBeString();
    /** @var string $id */
    $this->get(route('recipes.page', ['id' => $id]))
        ->assertOk()
        // "1,5" se guardó como 3/2 y la web lo muestra como en los planes.
        ->assertSeeTextInOrder(['Tomate triturado', '½', 'bote', 'Aceite de oliva', '1 ½', 'cucharada sopera']);
});

it('says so when a recipe has no preparation steps', function () {
    // Regla de negocio 12: los pasos son opcionales.
    $recipe = RecipeMother::chickpeaSalad();
    app(RecipeRepository::class)->save($recipe);

    $this->get(route('recipes.page', ['id' => $recipe->id()->value()]))
        ->assertOk()
        ->assertSeeText('Esta receta no detalla pasos de preparación.');
});

it('escapes the recipe data, so HTML in a name is shown as text', function () {
    // CONCEPTO: escapado automático de Blade (protección frente a XSS)
    // Un nombre con una etiqueta <script> se guarda tal cual (para el
    // dominio es un texto no vacío), pero la página lo muestra como texto.
    $id = $this->postJson('/api/recipes', [
        'name' => '<script>alert("pisto")</script>',
        'servings' => 1,
        'ingredients' => [['name' => 'Sal', 'amount' => null, 'unit' => null]],
    ])->assertCreated()->json('id');
    expect($id)->toBeString();
    /** @var string $id */
    $this->get(route('recipes.page', ['id' => $id]))
        ->assertOk()
        // El segundo argumento (false) compara con el HTML en bruto.
        ->assertDontSee('<script>alert("pisto")</script>', false)
        ->assertSee('&lt;script&gt;', false);
});

it('accepts the id in upper case', function () {
    // whereUuid admite mayúsculas y el dominio normaliza el id.
    $recipe = RecipeMother::chickpeaSalad();
    app(RecipeRepository::class)->save($recipe);

    $this->get('/recipes/'.strtoupper($recipe->id()->value()))->assertOk();
});

it('shows the 404 page in Spanish when the recipe does not exist', function () {
    // El mismo RecipeNotFound que en la API es un 404 JSON; en la web, la
    // página de error de la aplicación con el mensaje del caso de uso.
    $this->get('/recipes/01a19699-76e7-7a25-96dc-da0375dce729')
        ->assertNotFound()
        ->assertSeeText('Página no encontrada')
        ->assertSeeText('No existe ninguna receta con el identificador «01a19699-76e7-7a25-96dc-da0375dce729».');
});

it('shows the 404 page in Spanish when the id is malformed', function () {
    // Decisión de HEXM-0016: whereUuid('id') en la ruta web. Para una
    // persona, /recipes/abc es una página que no existe (la API, en cambio,
    // responde 422: ADR 0008).
    $this->get('/recipes/abc')
        ->assertNotFound()
        ->assertSeeText('Página no encontrada')
        ->assertSeeText('La página que buscas no existe.');
});
