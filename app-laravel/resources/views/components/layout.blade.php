{{--
| Layout común de las páginas web de la aplicación (HEXM-0016).
|
| CONCEPTO: componente Blade anónimo
| Un fichero en resources/views/components/ se usa como etiqueta HTML:
| <x-layout title="...">contenido</x-layout>. El contenido llega en $slot y
| los atributos como variables ($title). Es la forma moderna de Laravel de
| compartir un esqueleto entre páginas (antes: @extends y @section).
|
| Vive en resources/views, no en un contexto, porque es de TODA la
| aplicación: lo usan la página de una receta y la página de error 404, y lo
| usarán las pantallas de los planes.
|
| DECISIÓN: CSS en línea, sin npm ni Vite
| La tarea solo necesita una página legible. Un pipeline de frontend
| (Vite, Tailwind...) añadiría dependencias y un paso de compilación que no
| enseñan nada de arquitectura. Cuando haya un frontend de verdad (ver "Ideas
| para más adelante" del roadmap) se decidirá con un ADR.
--}}
@props(['title'])
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · Menú hexagonal</title>
    <style>
        :root { color-scheme: light dark; --accent: #2f7d5b; --muted: #6b7280; --line: #d9dee3; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; line-height: 1.5; }
        header, main { max-width: 46rem; margin: 0 auto; padding: 1rem 1.25rem; }
        header { border-bottom: 1px solid var(--line); color: var(--muted); font-size: .9rem; }
        h1 { margin: .5rem 0 .25rem; color: var(--accent); }
        h2 { margin-top: 2rem; font-size: 1.15rem; }
        .meta { color: var(--muted); margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: .45rem .5rem; border-bottom: 1px solid var(--line); }
        th { font-size: .85rem; color: var(--muted); font-weight: 600; }
        td.amount { white-space: nowrap; }
        .to-taste { font-style: italic; color: var(--muted); }
        ol { padding-left: 1.25rem; }
        ol li { margin-bottom: .4rem; }
        .empty { color: var(--muted); font-style: italic; }
    </style>
</head>
<body>
    <header>Menú hexagonal · Recetario</header>
    <main>
        {{ $slot }}
    </main>
</body>
</html>
