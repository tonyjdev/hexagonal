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

it('leaves not found exceptions outside the API to the default handling', function () {
    // Sin JSON, el manejador devuelve null y Laravel aplica su tratamiento
    // por defecto. Como NotFound no es una excepción HTTP de Laravel, eso es
    // un 500: cuando haya páginas web se decidirá cómo mostrarlo.
    Route::get('/_test/not-found', fn () => throw RecipeNotFound::withId(RecipeId::generate()));

    $this->get('/_test/not-found')->assertInternalServerError();
});
