# 0005. Identificadores UUID v7 generados en el dominio

**Fecha:** 2026-11-01 · **Estado:** aceptada · **Tarea:** HEXM-0005 · **Relacionado:** [ADR 0004](0004-lista-blanca-de-dependencias-en-el-nucleo.md)

## Contexto

`Recipe` será la primera entidad del proyecto (HEXM-0006), y una entidad necesita una identidad (`RecipeId`). Había que decidir dos cosas:

1. **Quién genera la identidad.** Lo habitual en Laravel es una clave autoincremental que asigna la base de datos al hacer `save()`. Así, la entidad no tiene identidad hasta que se persiste: el dominio no podría crear una receta completa, ni probarla en memoria, ni referenciarla desde otro contexto (`MealPlanning` solo conoce el `RecipeId`) sin pasar antes por PostgreSQL.
2. **Con qué se genera.** El núcleo solo admite PHP nativo (ADR 0004). Las opciones eran escribirlo con PHP nativo o autorizar una librería (`ramsey/uuid`, `symfony/uid`) con su ADR.

## Decisión

1. **El dominio genera la identidad** con `RecipeId::generate()`, en el momento de crear la entidad. El repositorio solo la guarda.
2. **Formato UUID versión 7** (RFC 9562): los primeros 48 bits son los milisegundos Unix y el resto es aleatorio. Los identificadores salen ordenados por fecha de creación, así que en PostgreSQL el índice de la clave primaria crece por el final, en vez de insertar en posiciones aleatorias como con un UUID v4. PostgreSQL 18 también trae `uuidv7()`, así que el formato es el mismo a ambos lados.
3. **Con PHP nativo, sin librería**: `microtime`, `pack`, `random_bytes` y `bin2hex`, en unas 15 líneas comentadas. La lista blanca sigue sin librerías autorizadas.
4. `RecipeId::fromString()` acepta **cualquier versión** de UUID bien formada (de la 1 a la 8, variante RFC) y la normaliza a minúsculas: lo que importa al leer un identificador que viene de fuera es que esté bien formado, no cómo se generó.

## Consecuencias

- **A favor:**
  - Las entidades nacen con identidad: se crean y se prueban sin base de datos, y los casos de uso pueden devolver el id antes de persistir.
  - El núcleo sigue sin dependencias externas.
  - Los identificadores no revelan cuántas recetas hay (un autoincremental sí) y no chocan si algún día se importan datos de otro sitio.
- **En contra:**
  - Mantenemos nuestro propio generador. Está cubierto por tests (formato, versión, variante, unicidad y marca de tiempo), pero no tiene los años de uso de una librería.
  - Dos UUID creados en el mismo milisegundo no tienen un orden garantizado entre sí. Para recetas que crea una persona a mano es irrelevante.
  - Un UUID ocupa más que un entero (16 bytes en el tipo `uuid` de PostgreSQL).
- **Pendiente:** cuando `MealPlanning` necesite sus propios identificadores (nivel 3), el generador se podrá extraer a un tipo común en `Shared\Domain`. No se hace ahora para no crear una abstracción compartida con un solo uso.
