"""Tests del lector del Excel de la semana 2, sin pasar por HTTP.

Como en ``test_nutritionist_reader.py``: cada test construye el Excel ficticio
(``workbooks.self_week_workbook``), lo estropea si hace falta y llama
directamente a ``read_self_week``.
"""

import pytest
from openpyxl import Workbook
from workbooks import SELF_DAYS, as_file, self_week_workbook

from importer.reading.models import Ingredient, SelfWeek
from importer.reading.problems import InvalidWorkbookError, Problem
from importer.reading.self_week import read_self_week


def read(workbook: Workbook) -> SelfWeek:
    return read_self_week(as_file(workbook))


def problems_of(workbook: Workbook) -> list[Problem]:
    """Los problemas que encuentra el lector (falla si el Excel se lee sin problemas)."""
    with pytest.raises(InvalidWorkbookError) as raised:
        read(workbook)
    return raised.value.problems


def test_it_reads_the_seven_days_from_8_to_14_with_the_13_empty() -> None:
    week = read(self_week_workbook()).self_week

    assert [day.day for day in week] == [8, 9, 10, 11, 12, 13, 14]
    saturday = week[5]
    assert (saturday.lunch, saturday.dinner) == (None, None)


def test_it_reads_each_dish_with_its_name_as_written() -> None:
    week = read(self_week_workbook()).self_week

    assert [
        (day.day, day.lunch.name, day.dinner.name)
        for day in week
        if day.lunch is not None and day.dinner is not None
    ] == [(day, lunch[0], dinner[0]) for day, lunch, dinner in SELF_DAYS]


def test_each_ingredient_keeps_its_whole_text_and_no_quantity() -> None:
    monday = read(self_week_workbook()).self_week[0]

    # La cantidad no se separa (nivel 9): va dentro del nombre y quantity es None.
    assert monday.lunch is not None
    assert monday.lunch.ingredients == [
        Ingredient(name="½ bote de lentejas", quantity=None),
        Ingredient(name="1 zanahoria", quantity=None),
        Ingredient(name="½ cebolla", quantity=None),
    ]


def test_a_dish_without_ingredients_is_valid() -> None:
    sunday = read(self_week_workbook()).self_week[6]

    assert sunday.dinner is not None
    assert (sunday.dinner.name, sunday.dinner.ingredients) == ("Sopa de picadillo", [])


def test_a_missing_sheet_is_reported() -> None:
    workbook = self_week_workbook()
    del workbook["Detalle"]

    assert problems_of(workbook) == [Problem("Detalle", None, "falta la hoja")]


def test_a_repeated_day_is_reported_with_its_cell() -> None:
    workbook = self_week_workbook()
    # La fila 4 es la comida del día 9: pasa a ser otra comida del día 8.
    workbook["Detalle"]["A4"] = 8

    assert problems_of(workbook) == [
        Problem("Detalle", "A4", "la comida del día 8 está repetida"),
        Problem("Detalle", None, "falta la comida del día 9"),
    ]


def test_a_type_that_is_not_lunch_or_dinner_is_reported() -> None:
    workbook = self_week_workbook()
    workbook["Detalle"]["B2"] = "Merienda"

    assert problems_of(workbook) == [
        Problem("Detalle", "B2", "el tipo «Merienda» no es «Comida» ni «Cena»"),
        Problem("Detalle", None, "falta la comida del día 8"),
    ]


def test_a_day_out_of_range_and_a_missing_dish_are_reported_together() -> None:
    workbook = self_week_workbook()
    detail = workbook["Detalle"]
    detail["A2"] = 15  # la comida del día 8 pasa a ser del 15
    detail["C5"] = None  # la cena del día 9 se queda sin plato

    assert problems_of(workbook) == [
        Problem("Detalle", "A2", "el día debe ser del 8 al 14"),
        Problem("Detalle", "C5", "falta el plato de la cena del día 9"),
        Problem("Detalle", None, "falta la comida del día 8"),
    ]


def test_the_free_saturday_cannot_have_a_dish() -> None:
    workbook = self_week_workbook()
    # Las filas 12 y 13 son el día 13: se le pone una comida, en las dos hojas.
    workbook["Detalle"]["C12"] = "Pizza casera"
    workbook["Resumen"]["G2"] = "Pizza casera"

    assert problems_of(workbook) == [
        Problem("Detalle", "C12", "el día 13 es el sábado libre: no puede tener plato"),
        Problem("Resumen", "G2", "el día 13 es el sábado libre: no puede tener plato"),
    ]


def test_the_day_13_rows_can_be_left_out() -> None:
    workbook = self_week_workbook()
    workbook["Detalle"].delete_rows(12, 2)

    saturday = read(workbook).self_week[5]
    assert (saturday.day, saturday.lunch, saturday.dinner) == (13, None, None)


def test_the_summary_must_say_the_same_dishes_as_the_detail() -> None:
    workbook = self_week_workbook()
    summary = workbook["Resumen"]
    summary["C3"] = "Crema de calabaza"  # día 9, cena: distinto de «Detalle»
    summary["E2"] = None  # día 11, comida: vacío
    summary["B2"] = "LENTEJAS  ESTOFADAS"  # mayúsculas y espacios: es el mismo plato

    assert problems_of(workbook) == [
        Problem(
            "Resumen",
            "C3",
            "el plato «Crema de calabaza» no coincide con el de la hoja «Detalle» "
            "(celda C5: «Crema de verduras con queso»)",
        ),
        Problem(
            "Resumen",
            "E2",
            "falta el plato; en la hoja «Detalle» (celda C8) es «Macarrones con atún»",
        ),
    ]


def test_a_wrong_summary_header_is_reported() -> None:
    workbook = self_week_workbook()
    workbook["Resumen"]["A3"] = "Merienda"

    assert problems_of(workbook) == [
        Problem("Resumen", "A3", "se esperaba la cabecera «Cena» y hay «Merienda»")
    ]
