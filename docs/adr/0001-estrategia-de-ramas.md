# 0001. Estrategia de ramas: develop, staging y main

**Fecha:** 2026-10-31 · **Estado:** aceptada (staging y main como entornos: pendiente hasta que exista un despliegue)

## Contexto

El proyecto empieza como aprendizaje en local, pero más adelante se desplegará en un servidor o se convertirá en una aplicación, y para eso necesitará CI/CD. El usuario ya trabaja con el modelo `develop` → `staging` → `main` en otros proyectos (por ejemplo, `controlpanel`, con sus skills `/deploy-staging` y `/deploy-production`).

Se valoraron dos estrategias:

1. **Ramas por entorno** (`develop` → `staging` → `main`): cada rama representa un entorno y se promociona fusionando una en la siguiente.
2. **Trunk-based / GitHub Flow**: una sola rama principal; los entornos no son ramas, sino **despliegues del mismo artefacto**. Cada merge despliega en staging y una etiqueta de versión (`v1.2.0`) despliega en producción.

## Decisión

Se adopta la estrategia **1, por coherencia con los demás proyectos del usuario**, introducida por fases:

- **Ahora:** `main` y `develop`. Las ramas de tarea (`feat/hexm-NNNN-slug`) salen de `develop` y vuelven a ella por PR. `main` solo recibe promociones.
- **Cuando exista despliegue:** se crea `staging` y el CI/CD despliega cada rama en su entorno (`develop` en desarrollo o integración, `staging` en preproducción y `main` en producción). Las promociones se hacen por PR (`develop` → `staging` → `main`) y cada paso a `main` se etiqueta con una versión.

## Consecuencias

- **A favor:** es el mismo modelo mental y las mismas herramientas que en el resto de proyectos; da puntos de control claros antes de producción; facilita aprender CI/CD por entornos.
- **En contra (y cómo se mitiga):**
  - *Deriva entre ramas*: un *hotfix* aplicado directamente en `main` debe volver a `develop`. Regla: los arreglos siempre entran por `develop`, salvo una urgencia en producción, que se fusiona de vuelta enseguida.
  - *Se reconstruye en cada rama*: lo que se prueba en staging no es exactamente el binario de producción. Mitigación para cuando haya despliegue: construir **una imagen Docker por commit** y promocionar esa imagen (la etiqueta de la imagen viaja de entorno en entorno), aunque las ramas sigan marcando el entorno.
- **Cuándo revisarla:** si el proyecto se queda con una sola persona y despliegues sencillos, el modelo trunk-based (estrategia 2) es más ligero y sería razonable migrar a él. Se registraría en un ADR nuevo.
