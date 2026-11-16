<?php

/*
| Tests de la traducción global ServiceUnavailable → HTTP 503
| (withExceptions() en bootstrap/app.php, HEXM-0031).
|
| Misma técnica que NotFoundRenderingTest: una ruta temporal lanza una
| excepción real (PlanReaderUnavailable) y se observa la respuesta. Lo que se
| demuestra es que el manejador reconoce cualquier subclase de
| Shared\Application\ServiceUnavailable por su clase base, y que, al
| contrario que los errores de dominio y los «no encontrado», sí llega al log.
*/

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use MealPlanning\Application\PlanReaderUnavailable;

it('renders an unavailable service in the API as a 503 with its message in Spanish', function () {
    Route::get('/api/_test/unavailable', fn () => throw PlanReaderUnavailable::unreachable(new ConnectionException('Connection refused')));

    $this->getJson('/api/_test/unavailable')
        ->assertServiceUnavailable()
        // Solo el mensaje para el usuario: el detalle técnico se queda en el log.
        ->assertExactJson(['message' => 'Ahora mismo no se pueden leer los documentos del plan. Inténtalo de nuevo en unos minutos.']);
});

it('reports an unavailable service to the log', function () {
    Exceptions::fake();
    Route::get('/api/_test/unavailable', fn () => throw PlanReaderUnavailable::unreachable(new ConnectionException('Connection refused')));

    $this->getJson('/api/_test/unavailable');

    Exceptions::assertReported(PlanReaderUnavailable::class);
});
