# 0018. Eventos de dominio y su publicación

**Fecha:** 2026-11-05 · **Estado:** aceptada · **Tarea:** HEXM-0026 · **Relacionado:** [ADR 0004](0004-lista-blanca-de-dependencias-en-el-nucleo.md), [ADR 0006](0006-persistencia-del-agregado-recipe.md), [ADR 0012](0012-el-reloj-como-puerto-de-aplicacion.md), [ADR 0017](0017-puerto-recipecatalog-y-capa-anticorrupcion.md)

## Contexto

Se puede borrar una receta del recetario (`DELETE /api/recipes/{id}`), y los planes que la usan tendrán que enterarse (HEXM-0027). El recetario no debe saber que existen los planes: no puede llamarlos. Hace falta que **anuncie** lo que ha pasado, con un evento de dominio, `RecipeDeleted`. Es el primer evento del proyecto y plantea cinco preguntas:

1. **El tipo base** de los eventos.
2. **Quién crea el evento**: el agregado o el caso de uso.
3. **El puerto de publicación y su adaptador**: el *dispatcher* de Laravel o un bus propio.
4. **Cuándo se publica**: antes o después de confirmar el borrado (*commit*).
5. **Qué fecha lleva**, si el núcleo no puede leer el reloj del sistema (ADR 0012).

## Decisión

1. **`Shared\Domain\DomainEvent`**, una interfaz con `occurredOn(): DateTimeImmutable`.
   - Está en el *shared kernel* porque el bus y los agregados de cualquier contexto necesitan un tipo común. Es técnica, no un concepto del negocio.
   - Un evento se nombra en pasado y con palabras del negocio, y es inmutable.
2. **El agregado crea el evento; la aplicación lo publica.**
   - `Recipe::delete($on)` **registra** `RecipeDeleted` con el *trait* `Shared\Domain\RecordsEvents` (`record()` protegido y `releaseEvents()`, que entrega los eventos y los olvida).
   - La receta es quien sabe qué le ha pasado, así que ningún caso de uso que la borre puede olvidarse del evento. Y si mañana hay una regla para impedir el borrado, ese es el sitio.
   - El dominio no publica nada: no conoce el bus, que está en `Application`, y no hace efectos hacia fuera a mitad de una operación.
   - Se elige un *trait* y no una clase base `AggregateRoot` porque PHP solo permite heredar de una clase: cada agregado decide si registra eventos sin cambiar su jerarquía.
3. **El evento lleva datos primitivos**: `recipeId()` devuelve el id como texto, más `occurredOn()`.
   - Un evento es un mensaje hacia fuera: quien lo escucha no debería necesitar el modelo del emisor (`RecipeId`) para leerlo, y un mensaje de datos simples se puede serializar tal cual (cola u *outbox*).
   - Se construye con `RecipeDeleted::of(RecipeId, fecha)`, así que nunca lleva un id inválido.
4. **El puerto es `Shared\Application\EventBus::publish(DomainEvent ...$events)`**, en `Shared\Application`, como el `Clock`: publicar es un efecto de la aplicación y es transversal.
   - En la aplicación real lo implementa **`Shared\Infrastructure\LaravelEventBus`**, sobre `Illuminate\Contracts\Events\Dispatcher`. Así los oyentes serán *listeners* normales de Laravel y no hay que reinventar su registro.
   - Para los tests, `InMemoryEventBus`, que apunta lo publicado.
   - Lo enchufa `SharedServiceProvider`.
5. **Se publica después del *commit*.**
   - `DeleteRecipe` busca la receta (o lanza `RecipeNotFound`), la marca como borrada, la borra con el repositorio y solo entonces publica lo que devuelve `releaseEvents()`.
   - Además, `LaravelEventBus` publica con `Connection::afterCommit()`: si la publicación ocurre dentro de una transacción, espera a que se confirme y la descarta si se deshace. Sin transacción abierta, publica en el momento.
   - Es el **contrato del puerto**: nunca se anuncia algo que no ha pasado.
   - En un Laravel clásico se marcaría el evento con `ShouldDispatchAfterCommit`, pero un evento del dominio no puede implementar una interfaz de Laravel.
6. **La fecha es la de `Clock::today()`**: el día del calendario en la zona del usuario. Al negocio le basta «se borró el 03/11», y no hace falta añadir un `now()` al puerto `Clock`.
7. **`RecipeRepository::delete(RecipeId)` es idempotente.** Borrar algo que no existe no hace nada; decir «no existe» es trabajo del caso de uso.
   - En Eloquent es un único `DELETE` de la fila de la receta, y sus ingredientes y pasos caen por `ON DELETE CASCADE` (la frontera del agregado está escrita en el esquema).
   - `DELETE /api/recipes/{id}` responde 204, 404 (`RecipeNotFound`) o 422 (id mal formado), como `GET`.

## Consecuencias

- **A favor:**
  - El recetario anuncia el borrado sin conocer a nadie: añadir oyentes (HEXM-0027 u otros) no lo toca.
  - `DeleteRecipe` se prueba sin Laravel, con el repositorio, el reloj y el bus en memoria, incluido el orden «borrar y después publicar».
  - Los oyentes son los de Laravel, con su inyección de dependencias.
- **En contra:**
  - **Se puede perder un aviso**: si el proceso se cae entre el borrado y la publicación, el evento no llega. Es el riesgo más tolerable (un plan que apunta a una receta borrada ya está previsto, ADR 0016). El remedio completo, el patrón ***outbox*** (guardar el evento en una tabla dentro de la misma transacción del borrado y publicarlo después, con reintentos), queda fuera de alcance, igual que las colas.
  - **Entrega síncrona**: los oyentes se ejecutan en la misma petición. Un oyente lento o que falle afecta a la respuesta, aunque la receta ya esté borrada.
  - `afterCommit()` espera a la transacción de la conexión **por defecto**, que es la de todos los repositorios del proyecto. Si un contexto usara otra conexión, habría que revisarlo.
  - **Frontera pendiente para HEXM-0027**: como el agregado crea el evento, `RecipeDeleted` vive en `Recipes\Domain`. Un oyente en `MealPlanning\Infrastructure` lo tendría que usar, y eso lo prohíbe el test de arquitectura del ADR 0017 («un contexto solo entra en otro por su capa de aplicación»). HEXM-0027 tendrá que decidir cómo cruza la frontera. Por ejemplo:
    - autorizar los eventos de un contexto como parte de su contrato público;
    - que `Recipes\Application` publique un evento de integración propio;
    - que el oyente lo traduzca en una capa anticorrupción.

    **Resuelto en el [ADR 0019](0019-reaccion-al-borrado-de-recetas-y-eventos-como-contrato-publico.md)**: los eventos de un contexto son su contrato público (`publicEvents()` en el test de arquitectura).
- **Revisar cuando:**
  - perder un aviso deje de ser tolerable, o un oyente necesite trabajo lento: *outbox* y colas;
  - haga falta la hora exacta de un evento: `now()` en el `Clock` (ADR 0012).
