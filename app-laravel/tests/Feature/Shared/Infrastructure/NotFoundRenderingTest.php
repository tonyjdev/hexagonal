<?php

/*
| Tests de la traducción global NotFound → HTTP 404
| (withExceptions() en bootstrap/app.php, HEXM-0011).
|
| Misma técnica que DomainExceptionRenderingTest: una ruta temporal lanza
| una excepción real (RecipeNotFound) y se observa la respuesta. Lo que se
| demuestra es que el manejador reconoce cualquier subclase de
| Shared\Application\NotFound por su clase base.
*/

use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Recipes\Application\RecipeNotFound;
use Recipes\Domain\RecipeId;

it('renders a not found exception in the API as a 404 with its message in Spanish', function () {
    $id = RecipeId::fromString('01a19699-76e7-7a25-96dc-da0375dce729');
    Route::get('/api/_test/not-found', fn () => throw RecipeNotFound::withId($id));

    $this->getJson('/api/_test/not-found')
        ->assertNotFound()
        ->assertExactJson(['message' => 'No existe ninguna receta con el identificador «01a19699-76e7-7a25-96dc-da0375dce729».']);
});

it('does not report not found exceptions to the log', function () {
    Exceptions::fake();
    Route::get('/api/_test/not-found', fn () => throw RecipeNotFound::withId(RecipeId::generate()));

    $this->getJson('/api/_test/not-found');

    Exceptions::assertNotReported(RecipeNotFound::class);
});

it('renders a not found exception outside the API as a 404 page in Spanish', function () {
    // HEXM-0016: en la web, el mismo NotFound se muestra como la página 404
    // de la aplicación (resources/views/errors/404.blade.php) con el mensaje
    // del caso de uso. Antes de esta tarea acababa en un 500.
    $id = RecipeId::fromString('01a19699-76e7-7a25-96dc-da0375dce729');
    Route::get('/_test/not-found', fn () => throw RecipeNotFound::withId($id));

    $this->get('/_test/not-found')
        ->assertNotFound()
        ->assertSee('Página no encontrada')
        ->assertSee('No existe ninguna receta con el identificador «01a19699-76e7-7a25-96dc-da0375dce729».');
});
