# Explicación HEXM-0008: Diagramas Archify en las tareas

## Explicación

Las explicaciones de cada tarea cuentan *por qué* el código es como es, pero lo hacen con texto y algún diagrama ASCII. Para entender un flujo de datos, un agregado o la forma del hexágono, un diagrama de verdad ayuda mucho más, y más aún si se puede explorar (zoom, recorrido animado, enlace al código de cada pieza).

El riesgo de los diagramas es que **se desactualizan**: se dibujan una vez y el código sigue cambiando. La idea de la solución es tratarlos como código:

- se describen en un JSON versionado (la fuente), que se revisa en los diffs como cualquier otro fichero;
- cada elemento cita los ficheros y líneas que lo respaldan en un **commit fijo**, y Archify comprueba que existen;
- se generan dentro del ciclo de cierre de cada tarea (`/close-task`), así que no dependen de acordarse.

## Lógica de negocio

No hay reglas del dominio nuevas: esta tarea mejora el material de estudio, no la aplicación. Sí cambia lo que se puede ver de las reglas existentes. Por ejemplo:

- en el diagrama de HEXM-0005, cómo "1 ½ bote" de un plan de la nutricionista acaba siendo exactamente 3/2 y por qué "al gusto" se rechaza como cantidad;
- en el de HEXM-0006, que una receta tiene al menos un ingrediente y nunca repite uno;
- en el de HEXM-0007, que dar de alta una receta inválida no deja nada guardado.

Las reglas son las mismas que en `docs/DOMINIO.md`. Lo que cambia es que ahora se ven dibujadas y enlazadas al código que las hace cumplir.

## Decisiones de arquitectura

Esta tarea no toca el núcleo (`src/`): es **infraestructura de documentación**. Las piezas y su sitio:

```
docs/diagramas/HEXM-NNNN/<slug>.<tipo>.json   ← fuente versionada (pocos KB)
        │  archify finalize (al cerrar la tarea)        archify deliver (en local)
        ▼                                                ▲
docs/diagramas/HEXM-NNNN/<slug>.html          ← generado, ignorado por git (~850 KB)
        │                                                │
        └── cada nodo enlaza a GitHub ──► código en el commit «Done» de la tarea
```

- **Solo se versiona la fuente.** Cada HTML ocupa unos 850 KB porque lleva el visor dentro. Con dos o tres diagramas por tarea, versionarlos añadiría decenas de MB, y cada regeneración otra copia completa al historial. El JSON pesa pocos KB, se lee en un diff y se regenera con `composer docs:diagrams` (`scripts/diagramas.sh`).
- **Dos commits al cerrar una tarea.** Archify verifica las líneas citadas contra *bytes confirmados* en una revisión concreta. Por eso `/close-task` hace primero el commit "Done" del código, después genera los diagramas apuntando a ese commit y por último hace un segundo commit con los diagramas y la documentación. El merge con `--merge` (no *squash*) conserva el commit "Done" en `develop`, así que los enlaces a GitHub siguen siendo válidos.
- **URL HTTPS del repositorio** en los diagramas: con la SSH (`git@github.com:…`), Archify valida pero no genera enlaces web.
- **Vocabulario del proyecto en la leyenda.** Los tipos de nodo de Archify están pensados para sistemas en la nube (`backend`, `security`, `messagebus`…). Se reetiquetan para que hablen de este proyecto: "Dominio: reglas de negocio", "Puerto (interfaz)", "Adaptador de salida"…
- **Las explicaciones ya cerradas no se modifican.** Deben coincidir con su documento de Workspace, y la CLI no permite editarlos. Por eso los diagramas del nivel 1 se enlazan desde los índices (`docs/explicaciones/README.md` y `docs/diagramas/README.md`) y desde un comentario en cada tarea.
- **Revisión visual obligatoria.** `finalize` comprueba el esquema, el render y la legibilidad en un navegador real, pero no si el diagrama *se entiende*. En el primer diagrama de flujo, la leyenda decía "Lógica del agente" y "Política": pasaba todas las comprobaciones y aun así se leía mal. Por eso el cierre incluye mirar la captura.

## Alternativas descartadas

- **Diagramas ASCII o Mermaid en el Markdown.** Son ligeros y GitHub renderiza Mermaid, pero no se pueden explorar, no enlazan al código y nadie comprueba que sigan siendo ciertos.
- **Versionar también los HTML.** Se pueden abrir sin Node nada más clonar, pero a costa de ~850 KB por diagrama y por cada regeneración. Se descartó porque el repositorio puede acabar siendo público como portfolio y debe seguir siendo ligero.
- **Publicarlos fuera del repositorio** (como páginas web o adjuntos de Workspace). La CLI de Workspace no admite adjuntos, y fuera del repo los diagramas se separarían del código que explican.
- **Generar los diagramas al empezar la tarea (del plan) además de al cerrarla.** Duplicaría el trabajo; al cierre el código ya es el definitivo y se puede citar.
- **Un único commit "squash" por tarea.** Es más limpio de leer, pero cambiaría el hash del commit que citan los diagramas y rompería sus enlaces.
- **Pasar a ADR esta decisión.** No afecta a la arquitectura de la aplicación, sino al proceso de documentación. Queda en `docs/diagramas/README.md` y `docs/TAREAS.md`.

## Conceptos aplicados

| Concepto | Definición breve | Dónde verlo |
|---|---|---|
| Diagramas como código | La fuente del diagrama es texto versionado y revisable; el dibujo es un artefacto generado. | `docs/diagramas/*/*.json`, `scripts/diagramas.sh` |
| Documentación respaldada por evidencia | Cada elemento cita ficheros y líneas en un commit fijo, y la herramienta lo verifica. | `meta.repository` y `sources` de cada JSON |
| Artefactos generados fuera de git | Lo que se puede reconstruir no se versiona: se ignora y se regenera. | `.gitignore`, `composer docs:diagrams` |
| Tipo de diagrama según la pregunta | Secuencia para un recorrido, flujo para una regla con ramas, arquitectura para capas, clases para un agregado. | Tabla de `docs/diagramas/README.md` |
| Proceso automatizado en el cierre | La documentación se produce en el mismo flujo que el código, no "cuando haya tiempo". | `.claude/skills/close-task/SKILL.md` (paso 5) |

## Diagramas

- [Cierre de una tarea con diagramas](../diagramas/HEXM-0008/cierre-de-tarea-con-diagramas.workflow.json) (`workflow`): ¿en qué orden se cierra ahora una tarea y por qué el diagrama necesita el commit "Done" antes de dibujarse? Muestra el gate, los dos commits, el bucle de validación y reparación de Archify y la regeneración local de los HTML.
- Diagramas del nivel 1 creados en esta tarea: [de texto a cantidad exacta](../diagramas/HEXM-0005/de-texto-a-cantidad-exacta.workflow.json) (HEXM-0005), [agregado Recipe](../diagramas/HEXM-0006/agregado-recipe.class.json) (HEXM-0006), [CreateRecipe paso a paso](../diagramas/HEXM-0007/create-recipe-paso-a-paso.sequence.json) y [hexágono del recetario](../diagramas/HEXM-0007/hexagono-recetario.architecture.json) (HEXM-0007).

Para verlos: `cd app-laravel && composer docs:diagrams` y abrir `docs/diagramas/HEXM-0008/cierre-de-tarea-con-diagramas.html` (o el de cada tarea) en el navegador.
