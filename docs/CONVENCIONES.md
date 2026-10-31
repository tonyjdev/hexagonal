# Convenciones del proyecto

Este documento recoge **cómo trabajamos** en el proyecto. Es de aplicación durante toda su vida, tanto para el usuario como para cualquier asistente de IA que colabore en él.

---

## 1. Idioma

| Qué | Idioma | Ejemplo |
|---|---|---|
| Nombres en código: clases, interfaces, métodos, variables, propiedades, enums, eventos, excepciones | **Inglés** | `Recipe`, `RecipeRepository`, `RecipeDeleted`, `MealType::Lunch` |
| Base de datos: tablas y columnas | **Inglés** | `recipes`, `nutrition_plans.starts_on` |
| Rutas y contratos de API (endpoints, claves JSON) | **Inglés** | `POST /api/recipes`, `{"servings": 2}` |
| Nombres de ficheros de código y namespaces | **Inglés** | `src/Recipes/Domain/Recipe.php` |
| **Comentarios en el código** | **Español** | `// Un value object no tiene identidad: …` |
| Documentación (`docs/`, README, ADRs) | **Español** | Este documento |
| Mensajes de commit y descripciones de PR | **Español** | `Añade el value object Quantity al recetario` |
| Conversación con el asistente | **Español** | — |
| Mensajes de error de dominio que verá el usuario final | **Español** | `"Una receta debe tener al menos un ingrediente"` |

La traducción oficial de cada término del negocio está en el **glosario** de [`DOMINIO.md`](DOMINIO.md). Si aparece un término nuevo, se añade allí antes de usarlo en el código, para que todos usemos siempre la misma palabra (esto es el *lenguaje ubicuo* de DDD).

---

## 2. Comentarios didácticos en el código

El código de este repositorio también es **material de estudio**. Por eso se comenta mucho más de lo habitual en un proyecto profesional.

### Qué debe explicar un comentario

1. **El porqué** antes que el qué: por qué esta clase existe, por qué está en esta capa, por qué no se hace de otra forma más obvia.
2. **El concepto** que se aplica, nombrándolo explícitamente para poder buscarlo después: *value object*, *agregado*, *puerto*, *adaptador*, *inyección de dependencias*, *Pydantic model*, *type hint*, *embedding*…
3. **La comparación con lo conocido**, cuando ayude: "en Laravel normalmente harías X; aquí hacemos Y porque…", "esto en Python equivale a … de PHP".
4. **Las alternativas descartadas**, si la decisión no es evidente.

### Formato

- **Cabecera de clase / módulo**: un bloque que explique su papel en la arquitectura (capa, contexto y responsabilidad).
- **Etiqueta de concepto** para que sea fácil localizar las explicaciones con una búsqueda (`grep "CONCEPTO:"`):

```php
/**
 * Quantity: cantidad de un ingrediente (valor + unidad).
 *
 * CONCEPTO: Value Object
 * No tiene identidad propia: dos Quantity con el mismo valor y la misma unidad
 * son intercambiables. Por eso es inmutable (readonly) y se compara por valor,
 * no por referencia. Si necesitas "cambiar" una cantidad, se crea otra nueva.
 *
 * CAPA: Dominio (Recipes\Domain). No depende de Laravel ni de nada externo.
 */
final readonly class Quantity
{
    // ...
}
```

- En **Python** se usan *docstrings* con la misma estructura y la misma etiqueta `CONCEPTO:`.
- En los **tests**, el nombre del test describe la regla de negocio y un comentario breve explica qué se está demostrando.

### Lo que no hacemos

- Comentarios que repiten el código (`// incrementa i`).
- Dejar código comentado "por si acaso": para eso está git.

---

## 3. Explicaciones en la conversación

Además de los comentarios, el asistente:

- **Antes** de un paso relevante, explica brevemente qué se va a hacer y por qué.
- **Después**, resume qué se ha creado, qué concepto se ha aplicado, y qué viene a continuación.
- Cuando aparece algo nuevo de Python/FastAPI o de IA, dedica unas líneas extra a explicarlo, relacionándolo con su equivalente en PHP/Laravel si existe.
- Propone pequeños **ejercicios opcionales** cuando haya uno natural ("prueba a añadir la regla X tú mismo").

---

## 4. Forma de trabajar

- **Por niveles**: seguimos [`ROADMAP.md`](ROADMAP.md). Cada nivel es pequeño, termina con tests en verde y deja la aplicación funcionando.
- **Dominio primero, framework después**: en cada contexto se empieza por el dominio puro y sus tests, y luego se añaden los adaptadores.
- **Tests**: Pest en PHP (incluidos *tests de arquitectura* que comprueban que el dominio no depende de Laravel) y pytest en Python.
- **Un commit por paso lógico**, con mensaje en español que explique el porqué.
- **Una etiqueta git por nivel terminado** (`nivel-01`, `nivel-02`…), para poder volver atrás y ver la evolución.
- **Tareas**: el trabajo se organiza en tareas `HEXM` gestionadas en Workspace; cada una se cierra con los documentos Resultado, Pruebas y Explicación. Ver [`TAREAS.md`](TAREAS.md).
- **Diario del proyecto**: al terminar cada nivel se escribe `docs/diario/NN-titulo.md` con lo hecho, los conceptos aprendidos, las decisiones tomadas y las dudas pendientes.
- **Decisiones de arquitectura importantes** se registran como ADR en `docs/adr/NNNN-titulo.md` (contexto → decisión → consecuencias).

---

## 5. Estructura del repositorio

```
hexagonal/
├── CLAUDE.md                 # instrucciones para el asistente
├── docs/
│   ├── CONVENCIONES.md       # este documento
│   ├── ROADMAP.md            # niveles y estado
│   ├── DOMINIO.md            # glosario y modelo de dominio
│   ├── TAREAS.md             # gestión de tareas con Workspace (HEXM)
│   ├── explicaciones/        # el porqué de cada tarea (copia de Workspace)
│   ├── diario/               # una entrada por nivel terminado
│   └── adr/                  # decisiones de arquitectura
├── app-laravel/              # núcleo hexagonal (PHP 8.5 / Laravel 12)
│   └── src/                  # código propio, fuera de app/ a propósito
│       ├── Recipes/          #   contexto Recetario
│       │   ├── Domain/
│       │   ├── Application/
│       │   └── Infrastructure/
│       ├── MealPlanning/     #   contexto Planes y calendario
│       │   ├── Domain/
│       │   ├── Application/
│       │   └── Infrastructure/
│       └── Shared/           #   piezas comunes mínimas
├── services/                 # servicios Python / FastAPI (a partir del nivel 7)
└── docker-compose.yml        # PostgreSQL (+ pgvector más adelante)
```

**Por qué `src/` y no `app/`**: `app/` es la carpeta del framework. Poner el dominio fuera deja claro, físicamente, que no pertenece a Laravel. Laravel queda como un adaptador más (controladores, Eloquent, service providers).

**Regla de dependencias** (la idea central de la hexagonal):

```
Infrastructure  ──►  Application  ──►  Domain
 (Laravel,           (casos de uso,     (entidades, value objects,
  Eloquent,           puertos de         reglas de negocio, puertos
  HTTP, FastAPI)      entrada)           de salida)
```

Las flechas solo van hacia dentro. El dominio no conoce nada de las capas exteriores.

---

## 6. Datos personales

- Los PDF y Excel reales de la nutricionista **no se versionan**: contienen datos de salud y el repositorio podría acabar siendo público como portfolio.
- Para tests y ejemplos se usan ficheros de muestra creados a mano en `tests/fixtures/` (con contenido ficticio o anonimizado).
