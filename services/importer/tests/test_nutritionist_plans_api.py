"""Tests de ``POST /nutritionist-plans/read`` con el cliente de pruebas de FastAPI.

Aquí solo se prueba la capa HTTP: que el fichero llegue al lector, que la
respuesta tenga la forma del contrato y que un Excel mal formado responda 422.
Los casos de lectura están en ``test_nutritionist_reader.py``.

CONCEPTO: *fixture* de pytest
Una función con ``@pytest.fixture`` prepara algo que los tests piden por su
nombre como parámetro (``client``): pytest la llama y les pasa el resultado.
Es inyección de dependencias en los tests, el equivalente al ``setUp()`` /
``beforeEach()`` de PHPUnit y Pest, pero cada test declara lo que necesita.
"""

import pytest
from fastapi.testclient import TestClient
from httpx2 import Response
from openpyxl import Workbook
from workbooks import FIXTURE, as_file, nutritionist_workbook

from importer.main import create_app

XLSX = "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"


@pytest.fixture
def client() -> TestClient:
    return TestClient(create_app())


def upload(client: TestClient, workbook: Workbook) -> Response:
    # ``files=`` envía un formulario multipart, como ``curl -F file=@plan.xlsx``.
    return client.post(
        "/nutritionist-plans/read",
        files={"file": ("plan.xlsx", as_file(workbook), XLSX)},
    )


def test_it_returns_the_nutritionist_part_of_the_plan_contract(client: TestClient) -> None:
    response = upload(client, nutritionist_workbook())

    assert response.status_code == 200
    body = response.json()
    # Las mismas claves que la parte de la semana 1 de POST /api/nutrition-plans
    # (ADR 0014), con los valores de los enums del dominio.
    assert set(body) == {
        "number",
        "fixed_meals",
        "exchanges",
        "exchange_rules",
        "nutritionist_week",
    }
    assert body["number"] == 99
    assert body["fixed_meals"][1] == {
        "meal_type": "mid_morning",
        "description": "1 pieza de fruta y elegir 2 de las opciones",
        "option_groups": [
            {"choose": 2, "options": ["1 yogur natural", "15 g de almendras", "2 lonchas de pavo"]}
        ],
    }
    assert body["exchanges"][0] == {
        "category": "carbohydrates",
        "food": "Arroz",
        "serving": "1/3 vaso",
    }
    assert [menu["menu"] for menu in body["nutritionist_week"]] == [1, 2, 3, 4, 5, 6]


def test_a_malformed_excel_responds_422_saying_where_the_problem_is(client: TestClient) -> None:
    workbook = nutritionist_workbook()
    del workbook["Desayunos y snacks"]
    workbook["Semana 1"]["C3"] = None

    response = upload(client, workbook)

    assert response.status_code == 422
    assert response.json() == {
        "message": "El Excel no tiene la forma esperada.",
        "errors": [
            {
                "sheet": "Semana 1",
                "cell": "C3",
                "message": "Hoja «Semana 1», celda C3: "
                "se esperaba la cabecera «Cena» y hay una celda vacía",
            },
            {
                "sheet": "Desayunos y snacks",
                "cell": None,
                "message": "Hoja «Desayunos y snacks»: falta la hoja",
            },
        ],
    }


def test_a_file_that_is_not_an_excel_responds_422(client: TestClient) -> None:
    response = client.post(
        "/nutritionist-plans/read",
        files={"file": ("plan.pdf", b"%PDF-1.7", "application/pdf")},
    )

    assert response.status_code == 422
    assert response.json()["errors"][0]["message"] == "El fichero no es un Excel (.xlsx) válido."


def test_the_versioned_fixture_is_the_fictitious_workbook(client: TestClient) -> None:
    # El .xlsx de tests/fixtures (el de las pruebas manuales) se lee igual que
    # el que construyen los tests: si alguien cambia uno sin el otro, falla.
    with FIXTURE.open("rb") as fixture:
        from_disk = client.post(
            "/nutritionist-plans/read", files={"file": ("plan.xlsx", fixture, XLSX)}
        )

    assert from_disk.status_code == 200
    assert from_disk.json() == upload(client, nutritionist_workbook()).json()


def test_the_endpoint_and_its_422_are_described_in_the_openapi_contract(
    client: TestClient,
) -> None:
    schema = client.get("/openapi.json").json()

    assert "/nutritionist-plans/read" in schema["paths"]
    assert {"NutritionistPlan", "InvalidWorkbookResponse"} <= set(schema["components"]["schemas"])
