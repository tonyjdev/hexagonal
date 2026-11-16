"""``POST /self-weeks/read``: lee el Excel de la semana 2 y lo devuelve como JSON.

El mismo adaptador de entrada fino que ``nutritionist_plans``, para el otro
documento del plan: recibe el fichero, llama a su lector
(``importer.reading.self_week``) y devuelve su modelo. Si el Excel está mal,
el lector lanza ``InvalidWorkbookError`` y el MISMO manejador de
``importer.api.errors`` responde 422: los dos formatos comparten la forma de
los errores.

CONCEPTO: una ruta por formato
Podría haber una sola ruta que adivinase qué Excel le llega (por sus hojas),
pero quien sube el fichero ya sabe cuál es, y adivinar sería otra forma de
fallar. Con una ruta por formato, cada una tiene su contrato en OpenAPI
(``NutritionistPlan`` o ``SelfWeek``) y un fichero equivocado da un 422 claro
(«falta la hoja «Detalle»»).
"""

from fastapi import APIRouter, UploadFile

from importer.api.errors import InvalidWorkbookResponse
from importer.reading.models import SelfWeek
from importer.reading.self_week import read_self_week

router = APIRouter(prefix="/self-weeks", tags=["self-weeks"])


@router.post(
    "/read",
    summary="Lee el Excel de la semana 2",
    responses={422: {"model": InvalidWorkbookResponse, "description": "El Excel no es válido"}},
)
def read_self_week_endpoint(file: UploadFile) -> SelfWeek:
    """Recibe el ``.xlsx`` en el campo ``file`` (multipart) y devuelve los días 8-14.

    Es ``def`` y no ``async def`` por lo mismo que la ruta de la nutricionista:
    openpyxl lee de forma bloqueante y FastAPI la ejecuta en un hilo aparte.
    """
    return read_self_week(file.file)
