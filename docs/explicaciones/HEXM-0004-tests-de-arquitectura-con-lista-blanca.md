# Explicación HEXM-0004: Tests de arquitectura con lista blanca

## Explicación

En HEXM-0003 la regla "el dominio no depende de Laravel" se convirtió en tests. Al preguntarnos **cómo** detectan esos tests que se usa PHP nativo y no Laravel, los pusimos a prueba y encontramos que estaban escritos como una **lista negra**: "no uses `Illuminate`, `App`, `Database`, `now()`, `collect()`…". Una lista negra solo atrapa lo que se te ocurrió prohibir. Pasaban sin problema:

- `Carbon\CarbonImmutable`, la librería de fechas que Laravel usa por debajo;
- `Laravel\Prompts\Prompt`, un paquete de Laravel con un namespace que no empieza por `Illuminate`;
- cualquier librería de terceros que use Laravel por dentro, porque el análisis solo ve lo que importa tu clase, no lo que importan sus dependencias.

La solución es invertir la pregunta. En lugar de "¿usa algo prohibido?", "¿usa algo que **no esté autorizado**?". Es una **lista blanca**: el núcleo solo puede usar PHP nativo, su propio código y las librerías que se autoricen expresamente (hoy, ninguna).

## Lógica de negocio

No hay reglas de negocio nuevas, pero la tarea protege a las que vendrán. Pensemos en la regla del calendario: "el plan 27 empieza un lunes, las semanas se alternan y el sábado es libre". Si el dominio calculara las fechas con Carbon, la pregunta "¿qué me toca comer el sábado 14?" dependería de cómo Carbon trata las zonas horarias o el inicio de semana, y eso puede cambiar al actualizar Laravel aunque la nutricionista no haya cambiado nada. Las reglas del plan solo deben cambiar cuando cambia el plan.

La lista blanca garantiza que el código que responde a esas preguntas usa únicamente PHP y nuestras propias definiciones (`MenuWeek`, `MenuDay`, `MealCalendar`…), y que cualquier excepción es una decisión consciente y documentada.

## Decisiones de arquitectura

```
                     ¿Puede Recipes\Domain usar X?

  Lista negra (HEXM-0003)                Lista blanca (HEXM-0004)
  ───────────────────────                ────────────────────────
  ¿X empieza por Illuminate,             ¿X es PHP nativo, Recipes\Domain,
   App, Database, now, collect…?          Shared\Domain o una librería
        │                                 autorizada?
     sí │ no                                    │
        ▼  ▼                                 sí │ no
     falla  PASA ← Carbon, Laravel\Prompts,     ▼  ▼
                   Symfony\Uid…              pasa  FALLA ← Carbon, Laravel\Prompts,
                                                           Symfony\Uid…
```

- **Qué puede usar cada capa:**

  | Capa | Lista blanca (además de PHP nativo) |
  |---|---|
  | `X\Domain` | `X\Domain`, `Shared\Domain` |
  | `X\Application` | `X\Domain`, `X\Application`, `Shared\Domain`, `Shared\Application` |

  `Infrastructure` no tiene lista blanca: es justo donde viven Laravel, Eloquent y los clientes HTTP.
- **Las dos listas conviven.** Todo lo que detecta la lista negra ya lo detecta la blanca, pero la negra explica mejor el error: "Expecting 'Recipes\Domain' not to use 'Illuminate'" es más útil que un listado de todo lo permitido.
- **Un único punto de autorización**, `authorizedLibraries()`, en el test. Autorizar una librería en el núcleo (por ejemplo, `ramsey/uuid` para los `RecipeId`) es una decisión de arquitectura: se añade allí y se justifica en un ADR. Se comprobó que el mecanismo funciona autorizando Carbon temporalmente.
- **Una expectativa por namespace** (*dataset* contexto × capa), por el falso negativo de Pest 5 con arrays como objetivo de `expect()` que se descubrió en HEXM-0003.
- **Un ADR nuevo, el 0004, en lugar de reescribir el 0003.** Un ADR aceptado es un registro histórico: si la decisión evoluciona, se escribe otro que la complementa o la sustituye y se enlazan.
- **Límite comprobado:** el código dinámico (`$fn = 'now'; $fn()`, `new $class`, `call_user_func('collect')`) no lo detectan los tests de arquitectura **ni PHPStan al nivel `max`**, que resuelve esos nombres y da 0 errores. Queda para la revisión de código. En el núcleo no hay ninguna razón legítima para escribirlo.

## Alternativas descartadas

- **Ampliar la lista negra** (añadir `Laravel`, `Carbon`…): es una carrera que siempre se pierde. Siempre habrá otro paquete u otra dependencia indirecta que no estaba en la lista.
- **El Laravel clásico:** no hay reglas. Los modelos y servicios usan Carbon, colecciones y facades donde haga falta, y nadie se plantea qué depende de qué. Funciona hasta que quieres probar el negocio sin el framework o actualizar una versión mayor.
- **Separar el núcleo en un paquete de Composer con su propio `composer.json`:** las dependencias quedarían declaradas en el `require` del paquete, y una herramienta como `composer-require-checker` detectaría lo que se usa sin declarar (Composer por sí solo no lo impide). Es la versión más fuerte, pero excesiva para dos contextos en un proyecto de aprendizaje; la lista blanca da casi la misma garantía sin la complejidad de varios paquetes.
- **deptrac:** permite reglas por capas con listas blancas, pero sería otra herramienta y otra configuración. Pest `arch()` ya está en el gate.
- **Permitir `Illuminate\Support\Collection` en el dominio por pragmatismo:** es habitual en equipos Laravel y no es una mala decisión, pero aquí el objetivo es aprender dónde está cada frontera, así que se elige la versión estricta.

## Conceptos aplicados

| Concepto | Definición breve | Dónde verlo |
|---|---|---|
| Lista blanca frente a lista negra (*allowlist* / *denylist*) | La negra prohíbe lo enumerado y permite el resto; la blanca permite lo enumerado y prohíbe el resto. | `allowedDependencies()` en `tests/Architecture/HexagonalLayersTest.php` (`CONCEPTO:`) |
| Dependencias explícitas | Cada dependencia del núcleo se declara y se justifica; nada entra por accidente. | `authorizedLibraries()`, `src/README.md` (lista blanca) |
| Dependencias indirectas | Lo que tus dependencias usan a su vez. El análisis estático de una clase no las sigue; la lista blanca las corta en la entrada. | ADR 0004, contexto |
| Límites del análisis estático | Se ve lo escrito en el código (`use`, tipos, `new`, llamadas), no lo que se decide al ejecutar. | ADR 0004, "Límite conocido" |
| ADR que complementa a otro | Las decisiones evolucionan con ADR nuevos enlazados, sin reescribir los anteriores. | `docs/adr/0003-…md` y `docs/adr/0004-…md` |
