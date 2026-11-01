# Nivel 01: Recetario (dominio)

**Fecha:** 2026-11-01 · **Etiqueta git:** `nivel-01`

Tareas: HEXM-0005 (value objects), HEXM-0006 (entidad `Recipe` e ingredientes) y HEXM-0007 (caso de uso `CreateRecipe` con repositorio en memoria).

## Qué se ha construido

- **Value objects del recetario** (HEXM-0005): `RecipeId` (UUID v7 generado por el dominio), `Quantity` (fracción exacta + unidad, que lee "½", "1 ½", "1,5", "1/2"…), `Unit` (enum con etiqueta en español), `Servings` y `Step`.
- **Excepción de dominio base** `Shared\Domain\DomainException`, con excepciones concretas (`InvalidQuantity`, `InvalidRecipe`…) y mensajes en español para el usuario final.
- **Entidad `Recipe`**, raíz del agregado, e **`Ingredient`** (value object con cantidad opcional, "al gusto") (HEXM-0006). Invariantes: nombre, al menos un ingrediente, sin ingredientes repetidos; pasos opcionales y ordenados.
- **Primer recorrido hexagonal completo** (HEXM-0007): el caso de uso `CreateRecipe` recibe un `CreateRecipeCommand` con primitivos, crea la receta con el dominio y la guarda a través del puerto `RecipeRepository`, implementado por el adaptador `InMemoryRecipeRepository`.
- **135 tests** en el gate (109 unitarios), y todo el nivel se prueba **sin arrancar Laravel ni PostgreSQL**.

```
test ─► CreateRecipe (Application) ─► Recipe::create() (Domain)
                    │
                    └─► RecipeRepository (Domain, puerto) ◄── InMemoryRecipeRepository (Infrastructure)
```

## Conceptos aprendidos

- **Value object:** sin identidad, inmutable (`final readonly`) y comparado por valor con `equals()`. `===` compara si es el mismo objeto en memoria.
- **Always-valid domain y constructores con nombre:** el constructor es privado; un objeto inválido no puede existir.
- ***Primitive obsession*:** por qué `Servings` no es un `int`.
- **Aritmética exacta:** ⅓ no cabe en un `float`; numerador/denominador sí.
- **Entidad y agregado:** `Recipe` se compara por su `RecipeId` y es la única puerta para cambiar sus ingredientes y pasos (frontera de consistencia).
- **Modelo rico frente a anémico:** `rename()` en vez de setters.
- **Colecciones inmutables sin `Collection`:** arrays copiados por valor, `readonly` y tipos `list<T>` para PHPStan.
- **Caso de uso:** orquesta, no decide; las reglas siguen en el dominio.
- **Puerto de salida, adaptador e inversión de dependencias:** la llamada va hacia fuera, pero la dependencia del código apunta hacia dentro.
- **Comando/DTO de entrada:** la frontera de la aplicación habla en primitivos.
- **Fake en memoria frente a mock:** comprobar el resultado, no las llamadas.

## Decisiones tomadas (y por qué)

- **UUID v7 generado en el dominio, con PHP nativo** ([ADR 0005](../adr/0005-identificadores-uuid-v7-en-el-dominio.md)): la receta nace con identidad, se prueba en memoria y el índice de PostgreSQL crece ordenado; no hace falta autorizar una librería en la lista blanca. Por eso tampoco hay `nextIdentity()` en el repositorio.
- **Fracciones como numerador/denominador simplificados**: exactas, comparables campo a campo y listas para sumar en la lista de la compra.
- **"Al gusto" y "una pizca" no son una `Quantity`**: son un ingrediente sin cantidad.
- **Excepción base en `Shared\Domain`**: un único manejador de Laravel la convertirá en HTTP 422 en el nivel 2.
- **Invariantes de la receta** (confirmadas por el usuario): pasos opcionales e ingredientes no repetidos por nombre.
- **El adaptador en memoria guarda copias (`clone`)**, para comportarse como una base de datos real y no esconder un `save()` olvidado.
- **Unidades desconocidas → error de dominio** (`Unit::tryFrom` + `InvalidIngredient::unknownUnit`), nunca un `ValueError` técnico.
- **`ext-mbstring` declarada** en `composer.json`, porque el dominio compara nombres con tildes y eñes.

## Dificultades / dudas

- **Datasets de closures en Pest:** con `fn () => fn () => …` y un parámetro `callable`, Pest pasa la closure exterior sin ejecutarla y el test no lanzaba nada (5 tests en rojo). Se resolvió con una única closure por caso y el parámetro tipado como `Closure`.
- **Los tests de arquitectura, probados de verdad:** se introdujo a propósito `Illuminate\Support\Str` en el dominio y el adaptador en memoria en la aplicación; en ambos casos fallaron la lista negra y la lista blanca. Siguen valiendo la lección del nivel 0: un test que no has visto fallar no demuestra nada.
- **Pendiente:** extraer un `Uuid` común a `Shared\Domain` cuando `MealPlanning` necesite identificadores (nivel 3), según el ADR 0005.

## Para profundizar

- Eric Evans, *Domain-Driven Design*, capítulos 5 (entidades y value objects) y 6 (agregados y repositorios).
- Vaughn Vernon, *Implementing Domain-Driven Design*, capítulos 6 (value objects), 10 (agregados) y 12 (repositorios).
- Martin Fowler, *AnemicDomainModel* y *Mocks Aren't Stubs* (artículos en martinfowler.com).
- RFC 9562 (UUID, incluida la versión 7).
- Ejercicios opcionales: añade `Quantity::times()` y comprueba que ⅓ × 3 = 1; escribe `Recipe::replaceIngredients()` reutilizando las invariantes; y escribe un test de `CreateRecipe` con un *mock* para ver qué deja de comprobar frente al fake en memoria.
