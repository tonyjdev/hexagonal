"""Capa HTTP del servicio: las rutas de FastAPI.

CAPA: adaptador de entrada (*driving*). Traduce peticiones HTTP a llamadas al
código del servicio y su resultado a JSON, como los controladores de
``app-laravel/src/*/Infrastructure/Http``. Aquí no se toman decisiones: la
lectura de los Excel (HEXM-0029 y HEXM-0030) vivirá fuera de este paquete y no
sabrá nada de FastAPI (ADR 0021).
"""
