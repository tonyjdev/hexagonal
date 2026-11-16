"""Capa HTTP del servicio: las rutas de FastAPI.

CAPA: adaptador de entrada (*driving*). Traduce peticiones HTTP a llamadas al
código del servicio y su resultado a JSON, como los controladores de
``app-laravel/src/*/Infrastructure/Http``. Aquí no se toman decisiones: la
lectura de los Excel vive fuera de este paquete, en ``importer.reading``, y no
sabe nada de FastAPI (ADR 0021). Los errores de la lectura se traducen a HTTP
en un único sitio, ``errors``.
"""
