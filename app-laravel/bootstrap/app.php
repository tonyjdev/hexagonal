<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Shared\Application\NotFound;
use Shared\Domain\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * ¿La petición espera una respuesta JSON? Las de la API (/api/*) siempre;
 * las demás, si lo piden con la cabecera Accept: application/json.
 */
$wantsJson = fn (Request $request): bool => $request->is('api/*') || $request->expectsJson();

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // Rutas de la API REST: prefijo /api y middleware `api` (sin estado).
        // Ver routes/api.php.
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) use ($wantsJson): void {
        $exceptions->shouldRenderJsonWhen($wantsJson);

        /*
        | CONCEPTO: traducir las excepciones de dominio en un único sitio
        | El dominio rechaza lo que el negocio no permite lanzando una
        | DomainException con un mensaje en español ("Una receta debe tener al
        | menos un ingrediente"). El dominio no sabe nada de HTTP, así que
        | alguien de fuera tiene que traducir ese error a una respuesta, y lo
        | hacemos AQUÍ, una sola vez para toda la aplicación:
        |
        |   - los controladores no llevan try/catch: dejan subir la excepción;
        |   - no hace falta conocer cada excepción concreta (InvalidQuantity,
        |     InvalidRecipe...): basta con capturar la clase base del shared
        |     kernel. Una excepción de dominio nueva, de cualquier contexto,
        |     queda cubierta sin tocar este fichero.
        |
        | Por qué 422 (Unprocessable Content): la petición está bien formada
        | (si no, la habría parado la validación de Laravel, que también da
        | 422), pero su contenido incumple una regla de negocio. No es un 400
        | (petición mal construida) ni un 500 (fallo nuestro).
        |
        | El cuerpo {"message": "..."} tiene la misma forma que el de los
        | errores de validación de Laravel, así un cliente lee el mensaje
        | siempre de la misma clave.
        |
        | Si la petición no espera JSON (una página web), se devuelve null y
        | Laravel aplica su tratamiento por defecto. Cuando haya un frontend
        | (ver "Ideas para más adelante" del roadmap) se decidirá cómo mostrar
        | ahí los errores de dominio.
        */
        $exceptions->render(function (DomainException $exception, Request $request) use ($wantsJson): ?JsonResponse {
            if (! $wantsJson($request)) {
                return null;
            }

            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        });

        /*
        | Un error de dominio es un error ESPERADO del usuario ("cantidad 0"),
        | no un fallo del sistema: no tiene sentido llenar el log con ellos ni
        | despertar a nadie. Laravel hace lo mismo con sus ValidationException.
        */
        $exceptions->dontReport(DomainException::class);

        /*
        | CONCEPTO: "no encontrado" también se traduce en un único sitio
        | Igual que con DomainException: un caso de uso de consulta lanza un
        | NotFound concreto (RecipeNotFound, HEXM-0011) y aquí se convierte en
        | HTTP 404 capturando la clase base de Shared\Application. Un "no
        | encontrado" nuevo de cualquier contexto (un plan, en el nivel 5)
        | queda cubierto sin tocar este fichero.
        |
        | Por qué 404 y no 422: la petición es correcta, pero el recurso que
        | pide no existe. No hay ninguna regla de negocio incumplida.
        |
        | Mismo cuerpo {"message": "..."} que los otros errores, para que el
        | cliente lea siempre el mensaje de la misma clave.
        |
        | CONCEPTO: el mismo error, distinta respuesta según el canal
        | En la web (HEXM-0016), el mismo RecipeNotFound se muestra como la
        | página resources/views/errors/404.blade.php con el mensaje en
        | español. Antes devolvía null y Laravel lo trataba como un error
        | cualquiera: un 500. El caso de uso no sabe nada de esto; solo dice
        | "no existe", y cada adaptador lo cuenta a su manera.
        */
        $exceptions->render(function (NotFound $exception, Request $request) use ($wantsJson): Response {
            if (! $wantsJson($request)) {
                return response()->view('errors.404', ['message' => $exception->getMessage()], Response::HTTP_NOT_FOUND);
            }

            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_NOT_FOUND);
        });

        // Buscar algo que no existe es una respuesta normal, no un fallo.
        $exceptions->dontReport(NotFound::class);
    })->create();
