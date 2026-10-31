# src/: el núcleo hexagonal

Aquí vive **el código propio de la aplicación**, organizado por *bounded contexts* (contextos delimitados) y, dentro de cada uno, por capas. Laravel (`app/`, `routes/`, `config/`…) queda fuera, como un adaptador más.

> **Por qué `src/` y no `app/`:** `app/` es la carpeta del framework. Poner el núcleo fuera deja claro, incluso físicamente, que no pertenece a Laravel. Ver [ADR 0003](../../docs/adr/0003-arquitectura-hexagonal-en-src.md).

## Contextos

| Contexto | Namespace | Responsabilidad |
|---|---|---|
| [Recipes](Recipes/README.md) | `Recipes\` | El recetario: recetas, ingredientes, cantidades y pasos. |
| [MealPlanning](MealPlanning/README.md) | `MealPlanning\` | Los planes nutricionales, sus semanas y días, los intercambios y el calendario. |
| [Shared](Shared/README.md) | `Shared\` | Piezas mínimas comunes a varios contextos. |

El glosario completo (español ↔ código) está en [`docs/DOMINIO.md`](../../docs/DOMINIO.md).

## Capas y regla de dependencias

```
┌──────────────────────────────────────────────────────────┐
│ Infrastructure                                           │
│   adaptadores: Eloquent, controladores HTTP, clientes    │
│   HTTP hacia FastAPI, service providers de Laravel...    │
│  ┌────────────────────────────────────────────────────┐  │
│  │ Application                                        │  │
│  │   casos de uso (CreateRecipe, GetMenuForDate...)   │  │
│  │  ┌──────────────────────────────────────────────┐  │  │
│  │  │ Domain                                       │  │  │
│  │  │   entidades, value objects, reglas de        │  │  │
│  │  │   negocio, eventos y PUERTOS (interfaces)    │  │  │
│  │  └──────────────────────────────────────────────┘  │  │
│  └────────────────────────────────────────────────────┘  │
└──────────────────────────────────────────────────────────┘
          Las dependencias solo apuntan HACIA DENTRO
```

| Capa | Puede usar | No puede usar |
|---|---|---|
| **Domain** | Solo PHP y `Shared\Domain` | Application, Infrastructure, Laravel (`Illuminate\`, `App\`), otros contextos |
| **Application** | Su Domain y `Shared\Domain`/`Shared\Application` | Infrastructure, Laravel, otros contextos |
| **Infrastructure** | Todo lo anterior y Laravel. Es la única capa que puede hablar con otro contexto, a través de su capa de aplicación | — |

**Estas reglas no son una convención: las comprueban los tests de arquitectura** (`tests/Architecture/`), que se ejecutan dentro de `composer ci:check`.

### Lista blanca: solo PHP y el propio núcleo

`Domain` y `Application` se comprueban con una **lista blanca** ([ADR 0004](../../docs/adr/0004-lista-blanca-de-dependencias-en-el-nucleo.md)): además de PHP nativo (`DateTimeImmutable`, `InvalidArgumentException`, `array_map`…), solo pueden usar lo que aparece en la columna "Puede usar". No basta con no usar Laravel: **cualquier** librería externa (Carbon, un paquete de Symfony, una librería que use Laravel por dentro…) hace fallar los tests.

**Para autorizar una librería en el núcleo** (por ejemplo, `ramsey/uuid`):

1. Escribe un ADR que explique por qué el núcleo la necesita y por qué no basta con PHP nativo.
2. Añade su namespace raíz a `authorizedLibraries()` en `tests/Architecture/HexagonalLayersTest.php`.

**Límite conocido:** los tests analizan el código sin ejecutarlo, así que no ven el código dinámico (`new $class`, `$fn()`). PHPStan tampoco lo señala. En el núcleo no se escribe, y lo vigila la revisión de código.
