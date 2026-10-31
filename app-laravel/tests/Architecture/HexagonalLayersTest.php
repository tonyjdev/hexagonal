<?php

/*
|--------------------------------------------------------------------------
| Tests de arquitectura: la regla de dependencias de la hexagonal
|--------------------------------------------------------------------------
|
| CONCEPTO: fitness functions (tests de arquitectura)
| Un test normal comprueba lo que hace el código ("crear una receta sin
| ingredientes falla"). Un test de arquitectura comprueba cómo está
| construido: qué clases pueden depender de cuáles. Así, la arquitectura deja
| de ser un dibujo en un documento y pasa a ser una regla que se comprueba en
| cada ejecución de `composer ci:check`.
|
| Pest los implementa con arch(): analiza el código (sin ejecutarlo) y
| comprueba los `use`, los tipos y las llamadas de cada clase de un namespace.
|
| CONCEPTO: regla de dependencias
|
|     Infrastructure ──► Application ──► Domain
|
| Las dependencias solo apuntan hacia dentro. El dominio no conoce nada de
| las capas exteriores, y por eso se puede probar sin Laravel, cambiar la
| base de datos sin tocarlo o enchufarle un servicio de Python sin que se
| entere. Ver src/README.md y docs/adr/0003-arquitectura-hexagonal-en-src.md.
|
| Mientras las capas estén vacías, estos tests pasan "trivialmente". Su valor
| aparece desde el nivel 1: el primer `use Illuminate\...` en el dominio los
| rompe.
*/

/**
 * Los bounded contexts del proyecto. Al añadir uno nuevo en src/, se añade
 * aquí (y en el autoload de composer.json).
 *
 * @return list<string>
 */
function businessContexts(): array
{
    return ['Recipes', 'MealPlanning'];
}

/**
 * Lo que "es Laravel" (o depende de él) y no puede entrar en el núcleo.
 *
 * - Illuminate: todo el framework (Eloquent, facades, Request, Collection...).
 * - App y Database: el código de la aplicación Laravel (app/, database/).
 * - Funciones globales de Laravel: también acoplan al framework aunque no
 *   haya ningún `use`. Por ejemplo, now() o collect() en el dominio harían
 *   que no se pudiera probar sin arrancar Laravel.
 *
 * @return list<string>
 */
function laravel(): array
{
    return [
        'Illuminate',
        'App',
        'Database',
        'app', 'config', 'env', 'now', 'today', 'collect', 'event', 'dispatch',
        'logger', 'cache', 'request', 'response', 'route', 'view', 'auth', 'session',
    ];
}

/**
 * Los otros contextos de negocio, vistos desde $context.
 *
 * @return list<string>
 */
function otherContexts(string $context): array
{
    return array_values(array_filter(businessContexts(), fn (string $other) => $other !== $context));
}

// ---------------------------------------------------------------------------
// Capa de dominio
// ---------------------------------------------------------------------------

arch('the domain does not depend on Laravel', function (string $context) {
    expect("{$context}\\Domain")->not->toUse(laravel());
})->with(fn () => [...businessContexts(), 'Shared']);

arch('the domain does not depend on outer layers', function (string $context) {
    expect("{$context}\\Domain")->not->toUse([
        "{$context}\\Application",
        "{$context}\\Infrastructure",
    ]);
})->with(fn () => [...businessContexts(), 'Shared']);

// ---------------------------------------------------------------------------
// Capa de aplicación
// ---------------------------------------------------------------------------

arch('the application layer does not depend on Laravel', function (string $context) {
    // Los casos de uso tampoco conocen el framework: si necesitan algo de
    // fuera (persistir, enviar un correo, transacciones...), lo piden a
    // través de un puerto (una interfaz) que implementa Infrastructure.
    expect("{$context}\\Application")->not->toUse(laravel());
})->with(fn () => [...businessContexts(), 'Shared']);

arch('the application layer does not depend on infrastructure', function (string $context) {
    expect("{$context}\\Application")->not->toUse("{$context}\\Infrastructure");
})->with(fn () => [...businessContexts(), 'Shared']);

// ---------------------------------------------------------------------------
// Fronteras entre bounded contexts
// ---------------------------------------------------------------------------

arch('a context core does not depend on other contexts', function (string $context, string $layer) {
    // CONCEPTO: bounded contexts desacoplados
    // MealPlanning solo conoce una receta por su RecipeId. Si necesita algo
    // más, lo pide a través de un puerto propio cuyo adaptador (en su
    // Infrastructure) habla con la capa de aplicación de Recipes. Por eso el
    // dominio y la aplicación de un contexto nunca importan otro contexto.
    //
    // OJO: cada capa se comprueba por separado (dataset contexto × capa).
    // Con un array como objetivo, expect(['A', 'B'])->not->toUse(...) NO
    // detecta la violación en Pest 5 (comprobado en HEXM-0003), así que no
    // hay que agrupar namespaces en una sola expectativa.
    expect("{$context}\\{$layer}")->not->toUse(otherContexts($context));
})->with(fn () => businessContexts())->with(['Domain', 'Application']);

arch('the shared kernel does not depend on any context', function () {
    // CONCEPTO: shared kernel
    // Shared es común a todos; si dependiera de un contexto, ese contexto
    // quedaría acoplado a todos los demás a través de él.
    expect('Shared')->not->toUse(businessContexts());
});
