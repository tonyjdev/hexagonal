"""``POST /nutritionist-plans/read``: lee el Excel de la nutricionista y lo devuelve como JSON.

Es un adaptador de entrada fino: recibe el fichero, llama al lector
(``importer.reading.nutritionist``) y devuelve su modelo. No toma ninguna
decisión de traducción; si el Excel está mal, el lector lanza
``InvalidWorkbookError`` y el manejador de ``importer.api.errors`` responde 422.

Se llama ``read`` (y no ``import``) porque no guarda nada: lee y devuelve.
Quien da de alta el plan es Laravel (HEXM-0031 y siguientes).
"""

from fastapi import APIRouter, UploadFile

from importer.api.errors import InvalidWorkbookResponse
from importer.reading.models import NutritionistPlan
from importer.reading.nutritionist import read_nutritionist_plan

router = APIRouter(prefix="/nutritionist-plans", tags=["nutritionist-plans"])


# ``responses`` documenta en OpenAPI la forma del 422 propio (el de un Excel
# mal formado); sin él, el contrato solo mostraría el 422 genérico de FastAPI.
@router.post(
    "/read",
    summary="Lee el Excel de la nutricionista",
    responses={422: {"model": InvalidWorkbookResponse, "description": "El Excel no es válido"}},
)
def read_nutritionist_plan_endpoint(file: UploadFile) -> NutritionistPlan:
    """Recibe el ``.xlsx`` en el campo ``file`` (multipart) y devuelve el plan leído.

    CONCEPTO: ``UploadFile`` y *multipart/form-data*
    Un parámetro de tipo ``UploadFile`` le dice a FastAPI que espere un fichero
    en un formulario *multipart* (lo que envía un ``<input type="file">`` o
    ``curl -F file=@plan.xlsx``). Es el ``$request->file('file')`` de Laravel.
    FastAPI necesita la librería ``python-multipart`` para leer el formulario.
    ``file.file`` es el fichero temporal (en memoria o en disco si es grande),
    que el lector lee como cualquier fichero binario.

    CONCEPTO: ``def`` frente a ``async def`` en una ruta
    openpyxl lee el fichero de forma bloqueante. Con ``def``, FastAPI ejecuta
    la ruta en un hilo aparte y el servidor sigue atendiendo otras peticiones;
    con ``async def`` la lectura bloquearía el bucle de eventos entero. Regla
    práctica: ``async def`` solo si dentro se usa ``await``.
    """
    return read_nutritionist_plan(file.file)
