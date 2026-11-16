"""Tests de ``GET /health``.

CONCEPTO: pytest
Un test es una función cuyo nombre empieza por ``test_``, y se comprueba con
un ``assert`` normal de Python: pytest lo reescribe para que, si falla, enseñe
los dos valores comparados. Es lo mismo que un ``test('...', function () {...})``
de Pest con ``expect(...)->toBe(...)``, sin clases ni métodos de aserción.

CONCEPTO: TestClient
El cliente de pruebas de FastAPI (sobre httpx) llama a la aplicación en el
mismo proceso, sin levantar un servidor ni abrir un puerto. Es el equivalente
a ``$this->getJson('/api/...')`` en los tests de Laravel.
"""

from fastapi.testclient import TestClient

from importer.main import create_app


def test_health_responds_ok_so_anyone_can_check_the_service_is_alive() -> None:
    # Una aplicación nueva para este test (application factory): ningún estado
    # de otro test puede influir en este.
    client = TestClient(create_app())

    response = client.get("/health")

    assert response.status_code == 200
    assert response.json() == {"status": "ok", "service": "importer"}


def test_health_is_described_in_the_openapi_contract() -> None:
    # FastAPI genera el contrato OpenAPI a partir de los type hints: si la ruta
    # y su modelo de respuesta están bien declarados, aparecen en él. Es la
    # base de los tests de contrato con Laravel (HEXM-0031).
    client = TestClient(create_app())

    schema = client.get("/openapi.json").json()

    assert "/health" in schema["paths"]
    assert "HealthResponse" in schema["components"]["schemas"]
