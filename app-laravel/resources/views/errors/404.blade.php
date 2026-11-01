{{--
| Página 404 de la aplicación web (HEXM-0016).
|
| CONCEPTO: páginas de error personalizadas de Laravel
| Si existe resources/views/errors/{código}.blade.php, Laravel la usa para
| responder a cualquier excepción HTTP con ese código: una URL que no
| coincide con ninguna ruta, un id que no cumple whereUuid('id')...
|
| Además, el manejador global (bootstrap/app.php) la usa para los NotFound de
| la aplicación fuera de la API (RecipeNotFound), y entonces le pasa el
| mensaje del caso de uso en $message ("No existe ninguna receta con el
| identificador «…»").
|
| Por qué no se muestra $exception->getMessage(): las excepciones HTTP de
| Laravel traen mensajes técnicos en inglés ("The route … could not be
| found"). Solo se muestra el mensaje que pasa explícitamente nuestro
| manejador, que está en español y pensado para el usuario final.
--}}
<x-layout title="Página no encontrada">
    <h1>Página no encontrada</h1>
    <p>{{ $message ?? 'La página que buscas no existe.' }}</p>
    <p class="meta">Comprueba la dirección o vuelve a la <a href="{{ url('/') }}">página de inicio</a>.</p>
</x-layout>
