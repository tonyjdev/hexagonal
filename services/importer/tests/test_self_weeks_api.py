"""Tests de ``POST /self-weeks/read`` con el cliente de pruebas de FastAPI.

Solo la capa HTTP, como en ``test_nutritionist_plans_api.py``: los casos de
lectura están en ``test_self_week_reader.py``.
"""

import pytest
from fastapi.testclient import TestClient
from httpx2 import Response
from openpyxl import Workbook
from workbooks import SELF_WEEK_FIXTURE, as_file, nutritionist_workbook, self_week_workbook

from importer.main import create_app

XLSX = "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"


@pytest.fixture
def client() -> TestClient:
    return TestClient(create_app())


def upload(client: TestClient, workbook: Workbook) -> Response:
    return client.post(
        "/self-weeks/read",
        files={"file": ("semana-2.xlsx", as_file(workbook), XLSX)},
    )


def test_it_returns_the_self_week_part_of_the_plan_contract(client: TestClient) -> None:
    response = upload(client, self_week_workbook())

    assert response.status_code == 200
    body = response.json()
    # La misma forma que self_week en POST /api/nutrition-plans (ADR 0014).
    assert set(body) == {"self_week"}
    assert [day["day"] for day in body["self_week"]] == [8, 9, 10, 11, 12, 13, 14]
    assert body["self_week"][0]["lunch"] == {
        "name": "Lentejas estofadas",
        "ingredients": [
            {"name": "½ bote de lentejas", "quantity": None},
            {"name": "1 zanahoria", "quantity": None},
            {"name": "½ cebolla", "quantity": None},
        ],
    }
    assert body["self_week"][5] == {"day": 13, "lunch": None, "dinner": None}


def test_the_nutritionist_excel_uploaded_here_responds_422(client: TestClient) -> None:
    # Un Excel equivocado no se adivina: faltan las hojas de la semana 2.
    response = upload(client, nutritionist_workbook())

    assert response.status_code == 422
    assert [error["message"] for error in response.json()["errors"]] == [
        "Hoja «Detalle»: falta la hoja",
        "Hoja «Resumen»: falta la hoja",
    ]


def test_the_versioned_fixture_is_the_fictitious_workbook(client: TestClient) -> None:
    # Como con el de la nutricionista: el .xlsx de las pruebas manuales y el
    # de los tests no pueden divergir.
    with SELF_WEEK_FIXTURE.open("rb") as fixture:
        from_disk = client.post(
            "/self-weeks/read", files={"file": ("semana-2.xlsx", fixture, XLSX)}
        )

    assert from_disk.status_code == 200
    assert from_disk.json() == upload(client, self_week_workbook()).json()


def test_the_endpoint_and_its_422_are_described_in_the_openapi_contract(
    client: TestClient,
) -> None:
    schema = client.get("/openapi.json").json()

    assert "/self-weeks/read" in schema["paths"]
    assert "422" in schema["paths"]["/self-weeks/read"]["post"]["responses"]
    assert "SelfWeek" in schema["components"]["schemas"]
