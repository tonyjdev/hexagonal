# 0009. Adaptador web: vistas en el contexto y errores según el canal

**Fecha:** 2026-11-01 · **Estado:** aceptada · **Tarea:** HEXM-0016 · **Relacionado:** [ADR 0003](0003-arquitectura-hexagonal-en-src.md), [ADR 0007](0007-adaptador-http-validacion-de-forma-y-errores-de-dominio.md), [ADR 0008](0008-consultas-dto-de-lectura-y-no-encontrado.md)

## Contexto

HEXM-0016 añade la primera pantalla de la aplicación: `GET /recipes/{id}` muestra una receta en HTML. Es un segundo adaptador de entrada para la misma consulta que la API, y debía demostrar que un canal nuevo no obliga a tocar el núcleo. Es la primera pantalla, pero no será la última (las de los planes, el frontend de "Ideas para más adelante"), así que había que decidir:

1. **Dónde viven las vistas Blade:** en `resources/views`, lo habitual en Laravel, o dentro del contexto.
2. **Qué ve una persona ante los errores.** Los manejadores globales (ADR 0007 y 0008) solo respondían JSON. Fuera de la API devolvían `null`, y un `RecipeNotFound` acababa en un `500`.
3. **Qué responde la web a un id mal formado** (`/recipes/abc`). La API responde `422` con el mensaje del dominio (ADR 0008).
4. **Cómo mostrar cantidades y unidades** si `Unit::label()` solo da el singular ("200 gramo").

Condición de partida: `Domain` y `Application` no cambian (criterio de aceptación).

## Decisión

1. **Las vistas de un contexto viven en su capa `Infrastructure`.**
   - `src/Recipes/Infrastructure/Http/views/` se registra con `loadViewsFrom(…, 'recipes')` en `RecipesServiceProvider::boot()` y se usa como `view('recipes::show')`.
   - Lo que es de toda la aplicación va en `resources/views`: el *layout* (componente anónimo `<x-layout>`) y las páginas de error.
2. **Los errores se muestran según el canal, en un único manejador.** El de `Shared\Application\NotFound` en `bootstrap/app.php`:
   - con una petición JSON responde `404 {"message"}`;
   - con una petición web responde `404` con la página `resources/views/errors/404.blade.php` y el mensaje del caso de uso.

   La página no muestra los mensajes de las excepciones HTTP de Laravel, que están en inglés; usa un texto genérico en español.
3. **En la web, un id mal formado es una página que no existe.** La ruta web lleva `whereUuid('id')` y Laravel responde `404` con nuestra página antes de llegar al controlador. La API mantiene el `422` del ADR 0008: a un programa le interesa distinguir "no puede existir" de "no existe"; a una persona que escribe una URL, no.
4. **Ingredientes en una tabla sin concordancia de número** (Ingrediente · Cantidad · Unidad) y "Raciones: 2". La pluralización correcta es conocimiento del negocio y le corresponde al dominio; está registrada como `tech_debt` de HEXM-0016.
5. **Sin *pipeline* de frontend:** CSS en línea en el *layout*, sin npm ni Vite.

## Consecuencias

- **A favor:**
  - El núcleo no cambió (`git diff develop` sobre `Domain` y `Application` sale vacío). El controlador web y el de la API solo se diferencian en la presentación.
  - Todo el recetario, también su HTML, se lee desde `src/Recipes`.
  - Cualquier 404 de la web (ruta desconocida, `whereUuid`, un `NotFound` de cualquier contexto) muestra la misma página en español.
  - El mismo error de aplicación se cuenta a cada canal a su manera, sin que el caso de uso lo sepa.
- **En contra:**
  - Las vistas de los contextos quedan fuera del sitio donde un desarrollador de Laravel las buscaría primero.
  - La misma URL "lógica" con un id mal formado da `404` en la web y `422` en la API: es intencionado, pero hay que conocerlo.
  - La tabla evita la concordancia pero no la resuelve.
  - Una `DomainException` en la web sigue acabando en `500`. Hoy no puede ocurrir (la única página es de consulta y `whereUuid` filtra el id), pero sí con formularios.
- **Revisar cuando:**
  - Haya formularios web. Entonces los errores de dominio deberán volver al formulario con el mensaje (*redirect back with errors*) en lugar de terminar en un `500`.
  - Llegue un frontend real (Livewire, Inertia…). Entonces se decidirá el *pipeline* de recursos con su propio ADR.
