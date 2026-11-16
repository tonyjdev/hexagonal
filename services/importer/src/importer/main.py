"""Punto de entrada del servicio: crea la aplicación FastAPI y registra sus rutas.

CONCEPTO: raíz de composición
Es el único sitio donde se juntan las piezas del servicio, como los *service
providers* de Laravel o ``bootstrap/app.php``. El resto de módulos no se
conocen entre sí a través de ella.

CONCEPTO: ASGI
``app`` es una aplicación ASGI: un objeto que un servidor (uvicorn) sabe
llamar con cada petición. Es el papel que en PHP hacen PHP-FPM o el servidor
de ``php artisan serve`` con ``public/index.php``. Por eso se arranca con
``uvicorn importer.main:app`` (módulo ``importer.main``, objeto ``app``).
"""

from fastapi import FastAPI

from importer.api import health, nutritionist_plans, self_weeks
from importer.api.errors import invalid_workbook_handler
from importer.reading.problems import InvalidWorkbookError


def create_app() -> FastAPI:
    """Construye una aplicación nueva con todas sus rutas.

    CONCEPTO: application factory
    Una función que fabrica la aplicación en lugar de un objeto global creado
    al importar. Los tests pueden pedir una aplicación limpia cada vez (sin
    estado compartido entre tests), y más adelante se le podrán pasar
    dependencias o configuración distinta.
    """
    app = FastAPI(
        title="importer",
        summary="Lee los Excel de los planes nutricionales y los devuelve como JSON.",
        version="0.1.0",
    )
    app.include_router(health.router)
    app.include_router(nutritionist_plans.router)
    app.include_router(self_weeks.router)
    # Un Excel mal formado, en cualquier ruta, responde 422 con sus problemas.
    app.add_exception_handler(InvalidWorkbookError, invalid_workbook_handler)
    return app


# La aplicación que arranca uvicorn (``poe serve``).
app = create_app()
