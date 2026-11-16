"""``GET /health``: ¿está vivo el servicio?

Sirve para comprobar que el servicio arranca y responde, a mano (``curl``) o
desde fuera (Laravel o un orquestador, más adelante). No comprueba nada más:
el servicio no tiene base de datos ni dependencias que vigilar.
"""

from typing import Literal

from fastapi import APIRouter
from pydantic import BaseModel, ConfigDict

# CONCEPTO: APIRouter
# Un grupo de rutas que se registra después en la aplicación
# (``app.include_router``). Es como un fichero de ``routes/`` de Laravel, o
# como el ``Route::group()`` de un contexto: cada parte del servicio declara
# sus rutas y ``main.py`` las junta.
router = APIRouter(tags=["health"])


class HealthResponse(BaseModel):
    """Cuerpo de la respuesta de ``GET /health``.

    CONCEPTO: modelo de Pydantic
    Una clase que declara la forma de unos datos con *type hints*. Pydantic la
    usa en las dos direcciones: valida lo que entra y serializa lo que sale.
    Como respuesta, cumple el papel de un *API Resource* de Laravel (fija qué
    claves salen y con qué tipo) y FastAPI la usa además para generar la
    documentación OpenAPI (``/docs``).

    CONCEPTO: ``Literal``
    ``Literal["ok"]`` es un tipo cuyo único valor posible es ``"ok"``: mypy
    rechaza cualquier otro texto. Es más preciso que ``str``, como un enum de
    un solo caso.
    """

    # frozen=True: inmutable una vez creado, como un ``readonly class`` de PHP.
    model_config = ConfigDict(frozen=True)

    status: Literal["ok"]
    service: Literal["importer"]


# CONCEPTO: decorador
# ``@router.get(...)`` envuelve la función de debajo y la registra como la
# ruta ``GET /health``. Es el equivalente a ``Route::get('/health', ...)``,
# pero escrito junto a la función. FastAPI lee sus *type hints*: el tipo de
# retorno es el modelo de la respuesta (lo valida y lo documenta).
@router.get("/health", summary="Comprueba que el servicio responde")
def health() -> HealthResponse:
    """Responde siempre 200 con ``{"status": "ok", "service": "importer"}``."""
    return HealthResponse(status="ok", service="importer")
