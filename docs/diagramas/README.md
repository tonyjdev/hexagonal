# Diagramas

Cada tarea `HEXM` cerrada deja, **siempre que tenga algo que dibujar**, uno o varios diagramas que acompañan a su [Explicación](../explicaciones/README.md): el flujo de datos, la lógica de negocio, la arquitectura o la estructura de lo construido. Se generan con [Archify](https://github.com/tt-a1i/archify) (skill instalada en `~/.agents/skills/archify`) como HTML interactivos: zoom, tema claro/oscuro, animación del recorrido y exportación a SVG, PNG o WebM.

## Cómo verlos

En git solo se versiona la **fuente** de cada diagrama (`<slug>.<tipo>.json`, unos pocos KB). El HTML ocupa unos 850 KB porque lleva el visor dentro, así que se genera en local:

```bash
cd app-laravel && composer docs:diagrams     # o, desde la raíz: scripts/diagramas.sh [HEXM-NNNN]
```

Después se abre `docs/diagramas/HEXM-NNNN/<slug>.html` en el navegador. Requiere Node y la skill de Archify; con `ARCHIFY_CLI=/ruta/archify.mjs` se usa otra instalación.

## Convenciones

- **Ubicación:** `docs/diagramas/HEXM-NNNN/<slug>.<tipo>.json`, con `meta.output` apuntando a `docs/diagramas/HEXM-NNNN/<slug>.html`. El tipo en el nombre del fichero permite al script saber cómo renderizarlo.
- **Idioma:** `meta.locale: "es"`. Títulos, etiquetas y tarjetas en español; los nombres de código (`Recipe`, `save()`) tal cual.
- **Respaldados por el código:** cada elemento principal cita sus ficheros y líneas (`sources`) en un commit fijo (`meta.repository.revision`, con la URL HTTPS de GitHub). Archify comprueba que esas líneas existen en ese commit, y el visor enlaza cada elemento a su código en GitHub.
- **Calidad:** se generan con `archify finalize … --repo-root . --quality showcase`, que valida el esquema, renderiza, comprueba el artefacto y hace una prueba real en navegador.
- **Leyenda con el vocabulario del proyecto:** los tipos de nodo de Archify (`backend`, `security`, `database`…) se reetiquetan en `meta.legend.entries` (por ejemplo, `security` → "Dominio: reglas de negocio", `messagebus` → "Puerto (interfaz)").

## Qué tipo de diagrama usar

| Para explicar… | Tipo | Ejemplo |
|---|---|---|
| Capas, puertos, adaptadores y el sentido de las dependencias | `architecture` | [Hexágono del recetario](HEXM-0007/hexagono-recetario.architecture.json) |
| El recorrido de una petición o de un caso de uso entre objetos | `sequence` | [CreateRecipe paso a paso](HEXM-0007/create-recipe-paso-a-paso.sequence.json) |
| Una regla de negocio o una validación con sus ramas de error | `workflow` | [De texto a cantidad exacta](HEXM-0005/de-texto-a-cantidad-exacta.workflow.json) |
| Entidades, value objects y sus relaciones (agregados) | `class` | [Agregado Recipe](HEXM-0006/agregado-recipe.class.json) |
| Estados y transiciones (un plan vigente, una importación…) | `lifecycle` | — |
| Tablas, claves y relaciones de la base de datos (nivel 2 en adelante) | `erd` | — |
| Datos que fluyen entre servicios (Laravel ↔ FastAPI, embeddings…) | `dataflow` | — |

Si una tarea no tiene nada que dibujar (por ejemplo, un cambio de configuración), su Explicación lo dice en la sección "Diagramas".

## Índice

### Nivel 1: Recetario (dominio)

| Tarea | Diagrama | Qué muestra |
|---|---|---|
| HEXM-0005 | [De texto a cantidad exacta](HEXM-0005/de-texto-a-cantidad-exacta.workflow.json) (`workflow`) | Cómo "1 ½" se convierte en la fracción exacta 3/2 y cuándo salta cada error de `InvalidQuantity`. |
| HEXM-0006 | [Agregado Recipe](HEXM-0006/agregado-recipe.class.json) (`class`) | La entidad raíz, sus value objects, la composición, las invariantes y la jerarquía de excepciones. |
| HEXM-0007 | [CreateRecipe paso a paso](HEXM-0007/create-recipe-paso-a-paso.sequence.json) (`sequence`) | El recorrido de los datos de un alta, tanto válida como inválida. |
| HEXM-0007 | [Hexágono del recetario](HEXM-0007/hexagono-recetario.architecture.json) (`architecture`) | Las capas, el puerto, el adaptador y la inversión de dependencias al cerrar el nivel 1. |

### Transversal

| Tarea | Diagrama | Qué muestra |
|---|---|---|
| HEXM-0008 | [Cierre de una tarea con diagramas](HEXM-0008/cierre-de-tarea-con-diagramas.workflow.json) (`workflow`) | El flujo de `/close-task` con los dos commits, el bucle de validación y reparación de Archify y la regeneración local de los HTML. |
