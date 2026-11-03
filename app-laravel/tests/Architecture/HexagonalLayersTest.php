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
| aparece desde el nivel 1: el primer `use Illuminate\...` en el dominio (o
| de cualquier librería no autorizada, ver la lista blanca) los rompe.
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
 * Librerías externas autorizadas dentro del núcleo (Domain y Application).
 *
 * De momento, ninguna: el núcleo solo usa PHP nativo y su propio código.
 * Autorizar una librería (por ejemplo, ramsey/uuid para los identificadores)
 * es una decisión de arquitectura: se añade aquí su namespace y se justifica
 * en un ADR. Ver src/README.md.
 *
 * @return list<string>
 */
function authorizedLibraries(): array
{
    return [];
}

/**
 * La lista blanca de una capa: lo ÚNICO que puede usar, además de PHP nativo.
 *
 * CONCEPTO: lista blanca (allowlist) frente a lista negra (denylist)
 * Las reglas de "no uses Laravel" son una lista negra: prohíben lo que se
 * nos ha ocurrido prohibir, y todo lo demás pasa. En HEXM-0004 se comprobó
 * que dejaban entrar Carbon, Laravel\Prompts (otro namespace) o cualquier
 * librería que use Laravel por dentro. La lista blanca invierte la pregunta:
 * no "¿usa algo prohibido?", sino "¿usa algo que no esté autorizado?".
 * Las clases y funciones de PHP (DateTimeImmutable, array_map...) siempre
 * están permitidas.
 *
 * @return list<string>
 */
function allowedDependencies(string $context, string $layer): array
{
    $allowed = match ($layer) {
        // El dominio solo se conoce a sí mismo y al dominio compartido.
        'Domain' => ["{$context}\\Domain", 'Shared\\Domain'],
        // La aplicación orquesta su dominio; puede usar puertos transversales
        // de Shared\Application (un EventBus, por ejemplo).
        'Application' => [
            "{$context}\\Domain",
            "{$context}\\Application",
            'Shared\\Domain',
            'Shared\\Application',
        ],
        default => throw new InvalidArgumentException("Capa sin lista blanca: {$layer}"),
    };

    // array_unique: para Shared, "{$context}\Domain" y 'Shared\Domain' coinciden.
    return array_values(array_unique([...$allowed, ...authorizedLibraries()]));
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
// Lista blanca del núcleo (Domain y Application)
// ---------------------------------------------------------------------------

arch('a core layer only uses PHP and its allowed dependencies', function (string $context, string $layer) {
    // Esta regla es la red de seguridad: cubre lo que las listas negras de
    // arriba no ven (librerías de terceros, paquetes de Laravel con otro
    // namespace, librerías que dependen de Laravel por dentro). Las reglas de
    // lista negra se mantienen porque su mensaje de error es más claro
    // ("Expecting 'Recipes\Domain' not to use 'Illuminate'").
    //
    // Como en las fronteras entre contextos, una expectativa por namespace:
    // en Pest 5 un array como objetivo de expect() puede dar falsos negativos.
    //
    // Límite conocido: el análisis es estático. El código dinámico
    // (new $class, $fn(), call_user_func('now')) no se detecta, y PHPStan
    // tampoco lo señala (comprobado en HEXM-0004). Queda para la revisión de
    // código: en el núcleo no hay razón legítima para escribirlo.
    expect("{$context}\\{$layer}")->toOnlyUse(allowedDependencies($context, $layer));
})->with(fn () => [...businessContexts(), 'Shared'])->with(['Domain', 'Application']);

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

// ---------------------------------------------------------------------------
// Persistencia
// ---------------------------------------------------------------------------

arch('Eloquent is only used in infrastructure', function () {
    // CONCEPTO: Eloquent es un adaptador
    // Los modelos de Eloquent son un detalle de cómo se guardan los datos.
    // Dentro de src/ solo pueden aparecer en la capa Infrastructure de cada
    // contexto (los repositorios y sus modelos, como
    // Recipes\Infrastructure\Persistence\Eloquent). Fuera de src/, Laravel
    // los usa en app/ (User) y database/ (factories, seeders).
    //
    // Las reglas de "el núcleo no usa Illuminate" ya lo impiden en Domain y
    // Application; esta lo dice en positivo y en un solo sitio, y cubre
    // también el caso de un contexto que guardara su modelo de Eloquent en
    // otro contexto.
    expect('Illuminate\Database\Eloquent')->toOnlyBeUsedIn([
        'App',
        'Database',
        ...array_map(fn (string $context) => "{$context}\\Infrastructure", [...businessContexts(), 'Shared']),
    ]);
});

// ---------------------------------------------------------------------------
// Adaptadores de Laravel: HTTP y service providers
// ---------------------------------------------------------------------------

/**
 * Dónde puede aparecer una pieza de Laravel dentro del código propio: en la
 * capa Infrastructure de cada contexto y en app/ (la aplicación Laravel).
 *
 * @return list<string>
 */
function laravelAdapterNamespaces(): array
{
    return [
        'App',
        ...array_map(fn (string $context) => "{$context}\\Infrastructure", [...businessContexts(), 'Shared']),
    ];
}

arch('HTTP is only used in infrastructure', function (string $http) {
    // CONCEPTO: HTTP es un adaptador driving (de entrada)
    // Los controladores (Illuminate\Http: Request, JsonResponse), las Form
    // Requests (Illuminate\Foundation\Http) y las rutas (Illuminate\Routing)
    // son la "puerta" HTTP de la aplicación. Viven en la capa Infrastructure
    // de su contexto (Recipes\Infrastructure\Http, HEXM-0010) y traducen la
    // petición a un comando del caso de uso. El núcleo no sabe que existe
    // HTTP: el mismo CreateRecipe lo podría llamar un comando de Artisan.
    //
    // Como la regla de Eloquent: la lista blanca del núcleo ya lo impide en
    // Domain y Application, pero aquí se dice en positivo, en un solo sitio
    // y con un mensaje claro. Una expectativa por namespace (en Pest 5 un
    // array como objetivo de expect() puede dar falsos negativos).
    expect($http)->toOnlyBeUsedIn(laravelAdapterNamespaces());
})->with(['Illuminate\Http', 'Illuminate\Foundation\Http', 'Illuminate\Routing']);

arch('service providers only live in infrastructure', function () {
    // CONCEPTO: el service provider es la raíz de composición
    // Es donde se enchufa cada puerto a su adaptador (RecipesServiceProvider,
    // HEXM-0010), así que conoce a los dos y tiene que estar FUERA del núcleo.
    // Un provider en Domain o Application metería el contenedor de Laravel
    // en el corazón de la aplicación.
    expect('Illuminate\Support\ServiceProvider')->toOnlyBeUsedIn(laravelAdapterNamespaces());
});

// ---------------------------------------------------------------------------
// El tiempo: el núcleo no lee el reloj del sistema (HEXM-0019)
// ---------------------------------------------------------------------------

/**
 * Funciones nativas de PHP que leen el reloj del sistema (o que, sin todos
 * sus argumentos, toman la fecha actual por defecto).
 *
 * @return list<string>
 */
function systemClockFunctions(): array
{
    return [
        'time', 'microtime', 'hrtime', 'date', 'gmdate', 'idate', 'getdate', 'localtime',
        'mktime', 'gmmktime', 'strtotime', 'date_create', 'date_create_immutable',
        'strftime', 'gmstrftime',
    ];
}

/**
 * Las únicas clases del núcleo que pueden leer el reloj del sistema, cada
 * una con su porqué.
 *
 * - Shared\Domain\Uuid: un UUID v7 lleva dentro los milisegundos en que se
 *   genera (ADR 0005). Ese instante no es un dato del negocio ("hoy"), sino
 *   una marca técnica para ordenar los identificadores, y Uuid::v7() ya es no
 *   determinista por diseño (random_bytes): ningún test depende de su valor.
 *   Pasarle el Clock tampoco encaja: el puerto está en Application (el
 *   dominio no lo ve) y da una fecha, no milisegundos (ADR 0012).
 *
 * @return list<string>
 */
function allowedSystemClockReaders(): array
{
    return ['Shared\\Domain\\Uuid'];
}

arch('the core does not read the system clock through functions', function (string $context, string $layer) {
    // CONCEPTO: el tiempo como dependencia (puerto Shared\Application\Clock)
    // Un caso de uso que llama a time() o a date() da un resultado distinto
    // cada día, y sus tests dejan de ser deterministas. "Hoy" se pide al
    // puerto Clock; quien lee de verdad el reloj es SystemClock, en
    // Infrastructure. Los now() y today() de Laravel ya están prohibidos por
    // la lista negra laravel(). Las clases de fecha (DateTimeImmutable) sí
    // están permitidas: ver el test siguiente.
    expect("{$context}\\{$layer}")->not->toUse(systemClockFunctions())->ignoring(allowedSystemClockReaders());
})->with(fn () => [...businessContexts(), 'Shared'])->with(['Domain', 'Application']);

/**
 * Los `new DateTime(...)` / `new DateTimeImmutable(...)` de un fichero que
 * leen el reloj del sistema: sin argumentos, o con un texto literal ('now',
 * 'today', '+1 day'...). Devuelve "línea: código" de cada uno.
 *
 * CONCEPTO: análisis por tokens (token_get_all)
 * PHP puede trocear su propio código en tokens, como hace el intérprete
 * antes de ejecutarlo. Así se distinguen el código de los comentarios (que
 * en este proyecto citan mucho `new DateTimeImmutable('now')`), y lo que hay
 * después del `new` y del paréntesis.
 *
 * @return list<string>
 */
function systemClockInstantiations(string $file): array
{
    // Sin espacios ni comentarios: solo el código que se ejecuta.
    $tokens = array_values(array_filter(
        PhpToken::tokenize((string) file_get_contents($file)),
        fn (PhpToken $token) => ! $token->isIgnorable(),
    ));
    $found = [];

    foreach ($tokens as $i => $token) {
        $class = $tokens[$i + 1] ?? null;
        $open = $tokens[$i + 2] ?? null;
        $argument = $tokens[$i + 3] ?? null;

        if (! $token->is(T_NEW) || $class === null || $open === null || $argument === null) {
            continue;
        }

        $isDateClass = in_array(ltrim($class->text, '\\'), ['DateTime', 'DateTimeImmutable'], true);
        $readsTheClock = $open->text === '(' && ($argument->text === ')' || $argument->is(T_CONSTANT_ENCAPSED_STRING));
        // `new DateTimeImmutable;` (sin paréntesis) también es "ahora".
        $readsTheClock = $readsTheClock || ! in_array($open->text, ['(', '::'], true);

        if ($isDateClass && $readsTheClock) {
            $call = match (true) {
                $open->text !== '(' => '',                // new DateTime;
                $argument->text === ')' => '()',          // new DateTime()
                default => "({$argument->text})",         // new DateTime('now')
            };
            $found[] = "{$token->line}: new {$class->text}{$call}";
        }
    }

    return $found;
}

/**
 * Los ficheros PHP de una capa del núcleo.
 *
 * @return list<string>
 */
function coreFiles(string $context, string $layer): array
{
    $directory = dirname(__DIR__, 2)."/src/{$context}/{$layer}";

    if (! is_dir($directory)) {
        return [];
    }

    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}

it('the core does not create dates from the system clock', function (string $context, string $layer) {
    // Lo que arch() no puede ver: `new DateTimeImmutable('now')` y
    // `new DateTimeImmutable($fecha)` usan la misma clase, pero solo el
    // primero lee el reloj. La regla: en el núcleo, una fecha se crea A
    // PARTIR DE OTRA (como hace MealCalendar con format('Y-m-d')), nunca sin
    // argumentos ni desde un texto escrito a mano. Una fecha fija en el
    // núcleo tampoco tiene sentido: las fechas llegan de fuera.
    //
    // Límite conocido (como en la lista blanca): el análisis es estático. Un
    // texto en una variable (new DateTimeImmutable($now) con $now = 'now')
    // no se detecta; queda para la revisión de código.
    $violations = [];

    foreach (coreFiles($context, $layer) as $file) {
        foreach (systemClockInstantiations($file) as $violation) {
            $violations[] = basename($file).":{$violation}";
        }
    }

    expect($violations)->toBe([], 'Usa el puerto Shared\Application\Clock en lugar de leer el reloj del sistema.');
})->with(fn () => [...businessContexts(), 'Shared'])->with(['Domain', 'Application']);
