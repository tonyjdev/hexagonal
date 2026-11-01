<?php

/*
| Tests de la traducción global DomainException → HTTP 422
| (withExceptions() en bootstrap/app.php).
|
| CONCEPTO: rutas de prueba registradas desde el test
| Para probar el manejador no hace falta esperar a un controlador real: se
| registra una ruta temporal que lanza una excepción de dominio y se observa
| cómo responde la aplicación. La ruta solo existe durante el test.
|
| Se usa una excepción real del recetario (InvalidRecipe) porque
| DomainException es abstracta: lo que se demuestra es que el manejador
| reconoce a CUALQUIER subclase por su clase base.
*/

use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Recipes\Domain\InvalidRecipe;

it('renders a domain exception in the API as a 422 with its message in Spanish', function () {
    Route::post('/api/_test/domain-error', fn () => throw InvalidRecipe::withoutIngredients());

    $this->postJson('/api/_test/domain-error')
        ->assertUnprocessable()
        ->assertExactJson(['message' => 'Una receta debe tener al menos un ingrediente.']);
});

it('does not report domain exceptions to the log', function () {
    // CONCEPTO: Exceptions::fake()
    // Sustituye el "reportador" de excepciones de Laravel por un doble de
    // test que anota lo que se reporta, para poder comprobarlo después.
    Exceptions::fake();
    Route::post('/api/_test/domain-error', fn () => throw InvalidRecipe::withoutIngredients());

    $this->postJson('/api/_test/domain-error');

    Exceptions::assertNotReported(InvalidRecipe::class);
});

it('keeps other exceptions as server errors', function () {
    // El manejador solo traduce errores de NEGOCIO. Un fallo técnico sigue
    // siendo un 500: si lo convirtiéramos en 422, culparíamos al cliente de
    // un error nuestro.
    Route::post('/api/_test/technical-error', fn () => throw new RuntimeException('Fallo técnico'));

    $this->postJson('/api/_test/technical-error')->assertInternalServerError();
});

it('leaves domain exceptions outside the API to the default handling', function () {
    // Una petición web que no pide JSON no recibe el 422 de la API. Cuando
    // haya un frontend se decidirá cómo mostrar ahí los errores de dominio.
    Route::get('/_test/domain-error', fn () => throw InvalidRecipe::withoutIngredients());

    $this->get('/_test/domain-error')->assertInternalServerError();
});
