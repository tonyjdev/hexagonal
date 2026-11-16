"""El contrato versionado en ``contracts/importer/`` sigue siendo el de los modelos.

Si un modelo de Pydantic o la salida de un lector cambia, estos tests fallan
hasta que se regenera el contrato con ``uv run poe contract``. El diff del PR
enseña entonces cómo cambia, y los tests de Laravel (que leen esos mismos
ficheros) dicen si Laravel lo sigue entendiendo (ADR 0024).

Se comparan los JSON ya leídos, no el texto: un cambio de formato (espacios,
orden de los saltos de línea) no es un cambio de contrato.
"""

import json
from pathlib import Path
from typing import Any

import pytest
from contract import CONTRACT_DIR, examples, files, schemas

REGENERATE = "El contrato de contracts/importer ha cambiado: regenéralo con `uv run poe contract`."


def read(path: Path) -> Any:
    return json.loads(path.read_text(encoding="utf-8"))


@pytest.mark.parametrize("path", sorted(files()), ids=lambda path: path.name)
def test_the_versioned_contract_matches_what_the_service_produces(path: Path) -> None:
    assert path.exists(), REGENERATE
    assert read(path) == files()[path], REGENERATE


def test_the_contract_directory_has_no_stale_files() -> None:
    # Un schema o un ejemplo que ya no se genera (un modelo renombrado) no
    # puede quedarse versionado: Laravel seguiría probando contra él.
    versioned = {path for path in CONTRACT_DIR.rglob("*.json")}

    assert versioned == set(files()), REGENERATE


def test_the_schemas_forbid_unknown_fields() -> None:
    # extra="forbid" en ContractModel llega al schema: Laravel puede confiar
    # en que una clave que no conoce es un error del contrato, no un extra.
    assert schemas()["nutritionist-plan.schema.json"]["additionalProperties"] is False
    assert schemas()["self-week.schema.json"]["additionalProperties"] is False


def test_the_examples_cover_a_saturday_and_an_invalid_workbook() -> None:
    # Los casos que el adaptador de Laravel tiene que saber traducir.
    generated = examples()

    assert {"day": 13, "lunch": None, "dinner": None} in generated["self-week.json"]["self_week"]
    assert generated["invalid-workbook.json"]["errors"]
