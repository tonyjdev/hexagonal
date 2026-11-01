# Nivel 02: Recetario (adaptadores)

**Fecha:** 2026-11-01 · **Etiqueta git:** `nivel-02`

Tareas: HEXM-0009 (repositorio Eloquent de recetas), HEXM-0010 (alta de recetas por API) y HEXM-0011 (consulta de recetas por API).

## Qué se ha construido

- **Persistencia real** (HEXM-0009):
  - `EloquentRecipeRepository`, el segundo adaptador del puerto `RecipeRepository`;
  - tres tablas para el agregado (`recipes`, `recipe_ingredients`, `recipe_steps`), la cantidad guardada exacta como numerador/denominador/unidad, y un *mapper* dominio ↔ filas;
  - guardado en una transacción por agregado;
  - tests de contrato que ejecutan la misma batería contra el adaptador en memoria y el de PostgreSQL.
- **Alta por HTTP** (HEXM-0010):
  - `POST /api/recipes`, el primer adaptador *driving*: `CreateRecipeController` + `CreateRecipeRequest` (validación de forma);
  - `RecipesServiceProvider`, con el *binding* del puerto;
  - un manejador global que traduce `DomainException` a `422` en español.
- **Consulta por HTTP** (HEXM-0011):
  - `GET /api/recipes/{id}` con el caso de uso de consulta `GetRecipe`, que devuelve un DTO de lectura (`RecipeView`);
  - `Quantity::amountAsText()` para escribir "1 ½" como en los planes;
  - `RecipeNotFound` → `404`, y el JSON con un *API Resource*.
- **220 tests** en el gate (eran 135 al cerrar el nivel 1).
- **`CreateRecipe` no ha cambiado en todo el nivel:** la base de datos y HTTP se enchufaron desde fuera.

```
            ┌──────────── Infrastructure ────────────┐
HTTP ──────►│ CreateRecipeController  GetRecipeController │
            │        │  RecipeResource ◄─┐   │            │
            └────────┼───────────────────┼───┼────────────┘
                     ▼                   │   ▼
              CreateRecipe          RecipeView ◄── GetRecipe        (Application)
                     │                       │
                     └──► RecipeRepository ◄─┘                      (Domain, puerto)
                                ▲
              EloquentRecipeRepository ──► PostgreSQL               (Infrastructure)
              RecipesServiceProvider: bind(puerto → adaptador)
```

## Conceptos aprendidos

- **Adaptadores *driven* y *driving*:** el repositorio de Eloquent lo usa el núcleo para salir; los controladores los usa el mundo para entrar. Los dos son traductores, sin lógica de negocio.
- **Data Mapper y persistencia del agregado:** el modelo de Eloquent no es la entidad. Un *mapper* traduce, y el agregado se guarda entero en una transacción.
- **Tests de contrato de un puerto:** la misma batería contra cada adaptador garantiza que el *fake* en memoria se comporta como el real.
- **Raíz de composición e inyección de dependencias con el contenedor:** `bind` del puerto en un *service provider* por contexto, y *autowiring* del resto.
- **Validación de forma frente a reglas de negocio:** la frontera comprueba el contrato y el dominio lo que el negocio permite. Es la metáfora del recepcionista y el chef.
- **Traducir excepciones en un único sitio:** una clase base en el *shared kernel* y un manejador global. `DomainException` → 422 y `NotFound` → 404.
- **CQS:** comandos (`CreateRecipe`) frente a consultas (`GetRecipe`). Y por qué todavía no CQRS.
- **DTO de lectura:** la entidad no sale de la aplicación.
- **El formato de salida es del adaptador:** el *API Resource* envuelve un DTO, no un modelo.
- **Tests de ida y vuelta y tests de mutación:** probar propiedades ("lo que escribo lo vuelvo a leer"), y estropear a propósito cada decisión para ver qué test la defiende.

## Decisiones tomadas (y por qué)

- **Reconstitución con `Recipe::create()`, sin constructor especial** ([ADR 0006](../adr/0006-persistencia-del-agregado-recipe.md)): los datos inválidos en la base de datos fallan al leer.
- **Clave compuesta `(recipe_id, position)` para ingredientes y pasos**: son value objects sin identidad, y su orden importa.
- **`CHECK` con `IS NOT NULL` explícito** para la cantidad, porque en SQL `NULL > 0` no es `false`. Lo destapó una prueba manual.
- **La `FormRequest` no repite reglas del dominio**: usa `present` en vez de `required`, y no lleva ni `min:1` ni `in:` ([ADR 0007](../adr/0007-adaptador-http-validacion-de-forma-y-errores-de-dominio.md)). Lo vigilan tests de mutación.
- **`toCommand()` deshace `ConvertEmptyStringsToNull`** en los textos, para que el vacío lo juzgue el dominio.
- **Un *provider* por contexto, con `bind`**, y los controladores en `Recipes\Infrastructure\Http`, no en `app/Http`.
- **Consultas** ([ADR 0008](../adr/0008-consultas-dto-de-lectura-y-no-encontrado.md)):
  - devuelven un DTO de lectura;
  - "1 ½" lo escribe el dominio;
  - `RecipeNotFound` vive en la aplicación (→ 404);
  - un id mal formado da 422 con el mensaje del dominio;
  - el JSON lo construye un *API Resource*.

## Dificultades / dudas

- **El `CHECK` de la cantidad aceptaba `(1, NULL, 'jar')`**: la lógica de tres valores de SQL. Se corrigió en HEXM-0009 tras una prueba manual.
- **Mutaciones que no mutan:** dos veces, el código "estropeado" se comportaba igual que el bueno. Una vez capturando `\LogicException`, padre de `\DomainException`; otra con `(string) null`, que es `""`. La lección: una mutación hay que verla fallar, igual que un test.
- **Pest y funciones globales entre ficheros:** una función auxiliar definida en un test solo existe si ese fichero se carga. Cada test define la suya.
- **Pendiente:**
  - mensajes de validación de Laravel en inglés (`tech_debt` de HEXM-0010);
  - el escapado de caracteres no ASCII en el JSON (`á`), que es válido pero poco legible con `curl`;
  - la asimetría `{"data": …}` del `GET` frente a `{"id": …}` del `POST`.

## Para profundizar

- Alistair Cockburn, *Hexagonal Architecture* (el artículo original sobre puertos y adaptadores, *driving* y *driven*).
- Martin Fowler, *Data Mapper* y *CQRS* (en martinfowler.com), y Bertrand Meyer sobre *Command-Query Separation*.
- Documentación de Laravel 13: *Service Container*, *Form Request Validation*, *Error Handling* y *Eloquent: API Resources*.
- Ejercicios opcionales:
  - un comando de Artisan `recipes:show {id}` que reutilice `GetRecipe` y escriba la receta en la terminal, sin tocar el caso de uso;
  - aceptar `amount` también como número en el `POST`, cambiando solo la `FormRequest`;
  - traducir los mensajes de validación al español (el `tech_debt` pendiente).
