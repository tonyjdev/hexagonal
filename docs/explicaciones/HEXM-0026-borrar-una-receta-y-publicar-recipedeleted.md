# Explicación HEXM-0026: Borrar una receta y publicar RecipeDeleted

## Explicación

El recetario no tenía forma de borrar una receta. Añadirla parece trivial (un `DELETE` y listo), pero tiene una consecuencia fuera del recetario: los planes pueden tener platos que apuntan a esa receta (HEXM-0024), y tendrán que enterarse (HEXM-0027).

El problema es **cómo se entera alguien sin que el recetario lo conozca**. Si `Recipes` llamara a `MealPlanning` al borrar, el recetario dependería de los planes, y cada contexto nuevo interesado obligaría a tocarlo. La solución es invertir la conversación: el recetario no llama a nadie, **anuncia lo que ha pasado** con un evento de dominio, `RecipeDeleted`, y quien quiera lo escucha.

La idea de la solución:

- **la receta registra el evento** al borrarse (`Recipe::delete()`), porque es quien sabe qué le ha pasado;
- **el caso de uso** (`DeleteRecipe`) la borra con el repositorio y, **después**, publica el evento por un puerto, `EventBus`;
- el adaptador del puerto usa el sistema de eventos de Laravel y **espera al *commit*** si hay una transacción en curso: nunca se anuncia algo que no ha pasado.

## Lógica de negocio

- **Una receta se puede borrar**, y se borra **entera**: el «Guiso de alubias y pollo» desaparece con sus ingredientes (alubias, pollo, sal…) y sus pasos. No quedan ingredientes sueltos de una receta que ya no existe.
- **Borrar una receta que no existe es un error** («no existe ninguna receta con ese identificador», un 404). Borrarla dos veces da lo mismo: la segunda vez ya no existe.
- **Un identificador que ni siquiera tiene forma de identificador** («guiso-de-alubias») se rechaza como dato inválido (422).
- **El borrado se anuncia**: «se ha borrado la receta 0190a… el 03/11/2026». Lo anuncia el recetario sin saber quién escucha. Hoy nadie reacciona; en HEXM-0027, los planes cuyos platos apuntaban a esa receta.
- **Solo se anuncia lo que ha pasado de verdad**: si la receta no existía, o el borrado se deshace, no se anuncia nada. Y se anuncia **una sola vez**.
- **La fecha es el día** en que se borró, en la zona horaria del usuario (Europe/Madrid): al negocio le basta «se borró el 03/11».

## Decisiones de arquitectura

### El recorrido de una petición

```
DELETE /api/recipes/{id}
  │
  ▼ Recipes\Infrastructure\Http
DeleteRecipeController          sin cuerpo que validar; 204 si todo va bien
  │
  ▼ Recipes\Application
DeleteRecipe
  1. RecipeId::fromString(id)               ← mal formado → InvalidRecipeId (422)
  2. RecipeRepository::find() ?? RecipeNotFound (404)   ← ni se borra ni se publica
  3. $recipe->delete(Clock::today())        ← Recipes\Domain: la receta REGISTRA RecipeDeleted
  4. RecipeRepository::delete(id)           ← un DELETE; ingredientes y pasos caen en cascada
  5. EventBus::publish(...releaseEvents())  ← Shared\Application: DESPUÉS de borrar
        │
        ▼ Shared\Infrastructure
     LaravelEventBus → Connection::afterCommit() → Dispatcher::dispatch(RecipeDeleted)
        │
        ▼
     listeners de Laravel (HEXM-0027: MealPlanning)
```

### El evento: qué es y dónde vive (ADR 0018)

- **`Shared\Domain\DomainEvent`** es la interfaz común de los eventos, con `occurredOn()`. Está en el *shared kernel* porque el bus y los agregados de cualquier contexto necesitan un tipo común, y es técnica, no un concepto del negocio.
- **`RecipeDeleted`** vive en `Recipes\Domain`, porque es un hecho del recetario. Se nombra en pasado (un hecho, no una orden como `DeleteRecipe`) y es inmutable (`readonly`).
- **Lleva datos simples**: el id como texto y el día. Un evento es un mensaje hacia fuera: quien lo escucha no debería necesitar el modelo del emisor (`RecipeId`) para leerlo, y un mensaje así se puede serializar tal cual si mañana va por una cola. Se construye con `RecipeDeleted::of(RecipeId, fecha)`, así que nunca lleva un id inválido.

### El agregado registra, la aplicación publica

- **`Recipe::delete($on)`** no hace desaparecer nada (un objeto en memoria no puede borrarse a sí mismo de la base de datos). Lo que hace es **anotar** el evento con el *trait* `Shared\Domain\RecordsEvents`: `record()`, protegido, para que nadie de fuera le invente eventos, y `releaseEvents()`, que entrega los eventos y los olvida para que no se publiquen dos veces.
- **Por qué el agregado y no el caso de uso**: la receta es quien sabe qué le ha pasado. Si mañana hay otro caso de uso que borre recetas, o una regla que impida borrar algunas, el evento y la regla están en el mismo sitio y nadie puede olvidarse de ellos.
- **Por qué el dominio no publica**: no conoce el bus (está en `Application`, y el dominio no puede verlo) y no debe hacer efectos hacia fuera a mitad de una operación. Publicar es una decisión de la aplicación, que sabe cuándo ha terminado la operación.
- **Por qué un *trait* y no una clase base `AggregateRoot`**: PHP solo permite heredar de una clase. Con un *trait*, cada agregado decide si registra eventos sin cambiar su jerarquía, como `use HasFactory` en un modelo de Laravel.
- **La fecha llega de fuera**: la pasa el caso de uso desde el puerto `Clock` (`today()`), porque el dominio no puede leer el reloj del sistema (ADR 0012).

### El puerto `EventBus` y sus adaptadores

- **`Shared\Application\EventBus::publish(DomainEvent ...$events)`**. Está en `Shared\Application` por la misma razón que el `Clock`: publicar es un efecto de la aplicación, y es transversal a todos los contextos. Es una interfaz propia y pequeña; el `Dispatcher` de Laravel tiene muchos más métodos, y el núcleo no conoce Laravel.
- **`LaravelEventBus`** (`Shared\Infrastructure`) traduce `publish()` a `Dispatcher::dispatch()`. Así los oyentes serán *listeners* normales de Laravel, con su inyección de dependencias, sin reinventar el registro de oyentes.
- **`InMemoryEventBus`** apunta lo publicado: con él, `DeleteRecipe` se prueba sin Laravel y se comprueba que publica **exactamente un** evento, o ninguno.
- Lo enchufa **`SharedServiceProvider`**, junto al reloj.

### Antes o después del *commit*

Es la decisión más delicada, porque publicar y borrar son dos cosas que pueden fallar por separado:

| | Si algo falla entre medias… | Consecuencia |
|---|---|---|
| Publicar **antes** de borrar | el borrado falla después de anunciarlo | los planes reaccionan a una receta que **sigue existiendo**: se anuncia algo falso |
| Publicar **después** de borrar | el proceso se cae antes de publicar | el aviso **se pierde**: un plan sigue apuntando a una receta borrada |

Se elige **después**: perder un aviso es más tolerable, porque una referencia a una receta borrada ya está prevista (ADR 0016) y HEXM-0027 la tratará. La garantía tiene dos capas:

- **el caso de uso** borra y luego publica (un test con un bus espía lo comprueba: cuando llega el evento, la receta ya no está);
- **el adaptador** publica con `Connection::afterCommit()`: si alguien ejecuta el caso de uso dentro de una transacción más grande, el evento espera al *commit*, y con un *rollback* se descarta. Sin transacción abierta, se publica en el momento.

En un Laravel clásico esto se haría marcando el evento con `ShouldDispatchAfterCommit`, pero un evento del dominio no puede implementar una interfaz de Laravel: por eso lo decide el adaptador.

### El borrado en la base de datos

`RecipeRepository::delete()` es **idempotente** (borrar algo que no existe no hace nada); decir «no existe» es trabajo del caso de uso, que la busca antes. En Eloquent es **un único `DELETE`** de la fila de `recipes`: las filas de `recipe_ingredients` y `recipe_steps` las borra PostgreSQL con `ON DELETE CASCADE`. La frontera del agregado («se borra entero») está escrita en el esquema, y por eso no hace falta una transacción ni un `DELETE` por tabla.

### La frontera pendiente para HEXM-0027

Como el agregado crea el evento, `RecipeDeleted` vive en `Recipes\Domain`. El oyente de `MealPlanning` estará en su `Infrastructure`, y desde HEXM-0025 la infraestructura de un contexto no puede usar el dominio de otro. Hoy no rompe nada, porque nadie escucha. HEXM-0027 tendrá que decidir cómo cruza la frontera:

- tratar los eventos como contrato público del contexto;
- que la capa de aplicación del recetario publique un evento propio para los demás contextos (un evento de integración);
- traducirlo en una capa anticorrupción.

Los datos simples del evento abaratan cualquiera de las tres. Queda registrado como `watchlist`.

## Alternativas descartadas

- **Un Laravel clásico**: `$recipe->delete()` sobre el modelo de Eloquent y `event(new RecipeDeleted($recipe))` en el controlador, o un *observer* `deleted` del modelo. Funciona, pero el evento sería del modelo de persistencia (no del dominio), dependería de Laravel y se dispararía por cualquier borrado técnico, incluso uno que no sea «el usuario ha borrado una receta». Y el controlador decidiría algo del negocio.
- **Llamar a `MealPlanning` desde `DeleteRecipe`**: el recetario dependería de los planes, justo lo contrario de lo que comprueban los tests de arquitectura.
- **Que el caso de uso cree el evento** (`new RecipeDeleted(...)` en `DeleteRecipe`): más simple, pero otro caso de uso que borrara recetas podría olvidarse de él.
- **Que el dominio publique directamente** (un bus estático o global en `Recipe::delete()`): el dominio conocería la infraestructura y el evento saldría antes de guardar.
- **Publicar antes del *commit***: se podría anunciar un borrado que luego se deshace.
- **El patrón *outbox*** (guardar el evento en una tabla dentro de la misma transacción y publicarlo después con reintentos): resuelve la pérdida de avisos, pero añade una tabla, un proceso y reintentos. Queda fuera de alcance; el ADR 0018 lo deja como punto de revisión.
- **Un bus propio síncrono**: se vería todo el mecanismo, pero habría que reescribir el registro de oyentes que Laravel ya trae.
- **Un instante (`Clock::now()`) como fecha del evento**: obligaba a ampliar el puerto `Clock` sin que el negocio lo necesite.
- **Borrar las filas hijas en el repositorio** (un `DELETE` por tabla dentro de una transacción): el `ON DELETE CASCADE` ya lo garantiza, incluso ante un borrado hecho a mano.

## Conceptos aplicados

| Concepto | Definición breve | Dónde verlo |
|---|---|---|
| Evento de dominio | Un hecho del negocio, en pasado, inmutable y con su fecha; quien lo publica no sabe quién lo escucha. | `app-laravel/src/Shared/Domain/DomainEvent.php`, `app-laravel/src/Recipes/Domain/RecipeDeleted.php` |
| El agregado registra sus eventos | El agregado anota lo que le pasa; la aplicación lo recoge y lo publica. | `Recipe::delete()`, `app-laravel/src/Shared/Domain/RecordsEvents.php` |
| *Trait* de PHP | Código que se copia dentro de la clase que lo usa; alternativa a la herencia. | `RecordsEvents` (`use RecordsEvents;` en `Recipe`) |
| Puerto de publicación | El núcleo publica por una interfaz propia; el adaptador decide cómo se entrega. | `app-laravel/src/Shared/Application/EventBus.php`, `LaravelEventBus`, `InMemoryEventBus` |
| Publicar después del *commit* | Nunca anunciar algo que no ha pasado; aceptar que un aviso se pueda perder. | `DeleteRecipe`, `LaravelEventBus::afterCommit()` |
| Patrón *outbox* (descartado) | Guardar el evento con la operación y publicarlo después, con reintentos. | ADR 0018 |
| Borrado idempotente | Repetir la operación deja el mismo resultado. | `RecipeRepository::delete()` y sus tests de contrato |
| Borrado en cascada | La base de datos borra las filas hijas con su padre. | Migración `create_recipes_tables`, `EloquentRecipeRepository::delete()` |
| Doble de test espía | Un doble que registra cómo y cuándo se le llama. | `tests/Unit/Recipes/Application/DeleteRecipeTest.php` (publicar después de borrar) |
| *Argument unpacking* | `...$lista` despliega una lista en argumentos sueltos. | `$this->events->publish(...$recipe->releaseEvents())` |

## Diagramas

- [Las piezas de un evento de dominio](../diagramas/HEXM-0026/piezas-del-evento.class.json) (`class`): ¿quién registra el evento y quién lo publica? Muestra `Recipe` usando el *trait* `RecordsEvents` y registrando `RecipeDeleted` (un `DomainEvent`), y `DeleteRecipe` publicándolo por el puerto `EventBus` con sus dos adaptadores.
- [Borrar una receta](../diagramas/HEXM-0026/borrar-una-receta.sequence.json) (`sequence`): ¿qué pasa en cada caso del borrado? Muestra los tres caminos de `DELETE /api/recipes/{id}`: registrar, borrar (en cascada) y después publicar, con el oyente avisado tras el *commit* (204); y 404 o 422 sin borrar ni publicar.

Para verlos: `cd app-laravel && composer docs:diagrams` y abrir `docs/diagramas/HEXM-0026/piezas-del-evento.html` y `docs/diagramas/HEXM-0026/borrar-una-receta.html` en el navegador.
