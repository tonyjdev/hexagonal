"""Traducción de los errores de la lectura a respuestas HTTP.

CONCEPTO: manejador de excepciones (*exception handler*)
FastAPI permite registrar una función por tipo de excepción
(``app.add_exception_handler``): si una ruta deja escapar esa excepción, la
función construye la respuesta. Es el ``->render()`` de
``bootstrap/app.php`` en Laravel, que traduce ``DomainException`` a 422 en un
único sitio (ADR 0007). Así ni el lector sabe de HTTP ni cada ruta tiene que
repetir un ``try``.
"""

from fastapi import Request
from fastapi.responses import JSONResponse
from pydantic import BaseModel, ConfigDict

from importer.reading.problems import InvalidWorkbookError

MESSAGE = "El Excel no tiene la forma esperada."


class ProblemResponse(BaseModel):
    """Un problema de forma: dónde está (hoja y celda, si se sabe) y qué pasa."""

    model_config = ConfigDict(frozen=True)

    sheet: str | None
    cell: str | None
    message: str


class InvalidWorkbookResponse(BaseModel):
    """Cuerpo de un 422 por un Excel mal formado.

    ``message`` resume y ``errors`` lleva un problema por elemento, con un
    texto en español que ya incluye su ubicación («Hoja «Semana 1», celda A1:
    …»), para que quien lo muestre no tenga que montarlo. Es el mismo reparto
    que el ``{"message", "errors"}`` de una *Form Request* de Laravel.
    """

    model_config = ConfigDict(frozen=True)

    message: str
    errors: list[ProblemResponse]


async def invalid_workbook_handler(request: Request, error: Exception) -> JSONResponse:
    """``InvalidWorkbookError`` → 422 con todos sus problemas.

    CONCEPTO: ``async def``
    Una corrutina: una función que puede ceder el turno mientras espera (E/S)
    sin bloquear el servidor. Los manejadores de excepciones de Starlette se
    declaran así. Aquí no se espera nada; solo se construye la respuesta.

    El parámetro es ``Exception`` (y no ``InvalidWorkbookError``) porque es la firma
    que pide ``add_exception_handler``; FastAPI solo la llama con la
    excepción registrada, y el ``assert`` se lo cuenta a mypy.
    """
    assert isinstance(error, InvalidWorkbookError)
    body = InvalidWorkbookResponse(
        message=MESSAGE,
        errors=[
            ProblemResponse(
                sheet=problem.sheet, cell=problem.cell, message=problem.located_message()
            )
            for problem in error.problems
        ],
    )
    return JSONResponse(status_code=422, content=body.model_dump())
