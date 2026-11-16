"""El contrato con Laravel: JSON Schema de las respuestas y ejemplos reales (HEXM-0031).

Laravel lee los Excel a través del puerto ``PlanReader``, y su adaptador HTTP
(``HttpPlanReader``) llama a este servicio. Los dos lados están escritos en
lenguajes distintos y se prueban por separado: ¿cómo se sabe que siguen
hablando el mismo JSON? Con un contrato versionado en el repositorio, en
``contracts/importer/`` (ADR 0024):

- ``*.schema.json``: el JSON Schema de cada respuesta, generado desde los
  modelos de Pydantic (``model_json_schema()``). Es la forma: campos,
  tipos, obligatorios y que no sobre nada (``extra="forbid"`` →
  ``"additionalProperties": false``).
- ``examples/*.json``: respuestas reales de las rutas, con los Excel
  ficticios de ``tests/workbooks.py``. Son lo que Laravel usa en sus tests
  con ``Http::fake()``, en lugar de respuestas escritas a mano que podrían
  no parecerse a las de verdad.

Este módulo genera los dos (``uv run poe contract``) y ``test_contract.py``
falla si lo versionado ya no coincide con lo que generan los modelos. Del
lado de Laravel, sus tests validan los ejemplos contra los schemas y los
pasan por el adaptador. Así, un cambio en un modelo de Pydantic obliga a
regenerar el contrato, y el contrato nuevo rompe los tests de Laravel si
Laravel no se adapta.

CONCEPTO: *contract testing*
Probar la conversación entre dos servicios sin levantarlos a la vez: cada
lado se prueba contra un mismo documento compartido (el contrato). Si el
proveedor (este servicio) cambia el contrato, sus tests lo detectan; si el
consumidor (Laravel) deja de entenderlo, los suyos. Es la idea de
herramientas como Pact, hecha aquí con dos ficheros por respuesta.

CONCEPTO: *snapshot test*
``test_contract.py`` compara lo generado con una «foto» versionada. Si el
cambio es intencionado, se regenera la foto y el diff del PR enseña
exactamente cómo cambia el contrato; si no lo es, el test lo para.

Vive en ``tests/`` y no en ``src/`` porque necesita los Excel ficticios
de ``workbooks.py``, que no forman parte del servicio.
"""

import json
from pathlib import Path
from typing import Any

from fastapi.testclient import TestClient
from openpyxl import Workbook
from pydantic import BaseModel
from workbooks import as_file, nutritionist_workbook, self_week_workbook

from importer.api.errors import InvalidWorkbookResponse
from importer.main import create_app
from importer.reading.models import NutritionistPlan, SelfWeek

# tests/ → services/importer → services → raíz del repositorio.
CONTRACT_DIR = Path(__file__).resolve().parents[3] / "contracts" / "importer"

XLSX = "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"

# Un schema por respuesta: la de cada ruta y la de su 422 (común a las dos).
SCHEMAS: dict[str, type[BaseModel]] = {
    "nutritionist-plan.schema.json": NutritionistPlan,
    "self-week.schema.json": SelfWeek,
    "invalid-workbook.schema.json": InvalidWorkbookResponse,
}


def schemas() -> dict[str, dict[str, Any]]:
    """El JSON Schema de cada respuesta, generado desde su modelo.

    CONCEPTO: ``model_json_schema(mode="serialization")``
    Pydantic describe un modelo como JSON Schema. ``mode="serialization"``
    describe lo que el modelo PRODUCE (la respuesta), no lo que acepta al
    validar: por ejemplo, ``quantity`` sale siempre, aunque al construir el
    modelo se pueda omitir porque tiene un valor por defecto.
    """
    return {name: model.model_json_schema(mode="serialization") for name, model in SCHEMAS.items()}


def _upload(client: TestClient, route: str, workbook: Workbook) -> tuple[int, Any]:
    response = client.post(route, files={"file": ("documento.xlsx", as_file(workbook), XLSX)})
    return response.status_code, response.json()


def examples() -> dict[str, Any]:
    """Respuestas reales de las rutas con los Excel ficticios.

    Se piden a la aplicación entera con el ``TestClient`` (no al lector), para
    que el ejemplo sea exactamente lo que recibiría Laravel: el JSON
    serializado por FastAPI, con su código de estado comprobado.
    """
    client = TestClient(create_app())

    status, nutritionist_plan = _upload(client, "/nutritionist-plans/read", nutritionist_workbook())
    assert status == 200
    status, self_week = _upload(client, "/self-weeks/read", self_week_workbook())
    assert status == 200
    # El Excel de la nutricionista subido como semana 2: un 422 con problemas reales.
    status, invalid_workbook = _upload(client, "/self-weeks/read", nutritionist_workbook())
    assert status == 422

    return {
        "nutritionist-plan.json": nutritionist_plan,
        "self-week.json": self_week,
        "invalid-workbook.json": invalid_workbook,
    }


def files() -> dict[Path, Any]:
    """Todo el contrato: ruta de cada fichero → su contenido JSON."""
    return {
        **{CONTRACT_DIR / name: schema for name, schema in schemas().items()},
        **{CONTRACT_DIR / "examples" / name: example for name, example in examples().items()},
    }


def dump(content: Any) -> str:
    """El JSON tal como se versiona: indentado, con tildes legibles y salto final."""
    return json.dumps(content, indent=2, ensure_ascii=False) + "\n"


if __name__ == "__main__":
    for path, content in files().items():
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(dump(content), encoding="utf-8")
        print(f"Guardado {path}")
