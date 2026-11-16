"""Tests del lector del Excel de la nutricionista, sin pasar por HTTP.

Cada test construye el Excel ficticio (``workbooks.nutritionist_workbook``),
lo estropea si hace falta y llama directamente a ``read_nutritionist_plan``:
la lectura se prueba como lógica pura, igual que los casos de uso del núcleo
se prueban sin controladores.
"""

from io import BytesIO

import pytest
from openpyxl import Workbook
from workbooks import MENUS, as_file, nutritionist_workbook

from importer.reading.models import ExchangeCategory, MealType, NutritionistPlan
from importer.reading.nutritionist import read_nutritionist_plan
from importer.reading.problems import InvalidWorkbookError, Problem


def read(workbook: Workbook) -> NutritionistPlan:
    return read_nutritionist_plan(as_file(workbook))


def problems_of(workbook: Workbook) -> list[Problem]:
    """Los problemas que encuentra el lector (falla si el Excel se lee sin problemas)."""
    with pytest.raises(InvalidWorkbookError) as raised:
        read(workbook)
    return raised.value.problems


def test_it_reads_the_plan_number_from_the_title() -> None:
    assert read(nutritionist_workbook()).number == 99


def test_it_reads_the_six_menus_of_week_one_as_written() -> None:
    plan = read(nutritionist_workbook())

    assert [(menu.menu, menu.lunch, menu.dinner) for menu in plan.nutritionist_week] == MENUS


def test_it_reads_the_fixed_meals_with_their_choose_n_groups() -> None:
    plan = read(nutritionist_workbook())

    breakfast, mid_morning, snack = plan.fixed_meals
    assert breakfast.meal_type == MealType.BREAKFAST
    assert breakfast.option_groups == []
    # La descripción se conserva entera, con el «elegir 2» dentro.
    assert mid_morning.description == "1 pieza de fruta y elegir 2 de las opciones"
    assert [(group.choose, group.options) for group in mid_morning.option_groups] == [
        (2, ["1 yogur natural", "15 g de almendras", "2 lonchas de pavo"])
    ]
    assert snack.meal_type == MealType.AFTERNOON_SNACK


def test_it_reads_the_exchanges_once_per_category_without_the_moment() -> None:
    plan = read(nutritionist_workbook())

    # Las proteínas y los vegetales aparecen en la comida y en la cena: la
    # columna «Momento» no se modela, así que se quedan una sola vez.
    assert [(e.category, e.food, e.serving) for e in plan.exchanges] == [
        (ExchangeCategory.CARBOHYDRATES, "Arroz", "1/3 vaso"),
        (ExchangeCategory.CARBOHYDRATES, "pan", "2 rebanadas"),
        (ExchangeCategory.PROTEIN, "Huevo", "2 uds"),
        (ExchangeCategory.PROTEIN, "pollo", "2 filetes"),
        (ExchangeCategory.PROTEIN, "pescado blanco", "1 filete grande"),
        (ExchangeCategory.VEGETABLES, "Vegetales", "> 1/2 plato"),
        (ExchangeCategory.FAT, "aguacate", "1/2"),
        (ExchangeCategory.FAT, "nueces", "1 puñado"),
        (ExchangeCategory.FAT, "aceitunas", "6-7"),
    ]


def test_it_collects_the_rules_from_the_sentences_and_the_category_notes() -> None:
    plan = read(nutritionist_workbook())

    assert plan.exchange_rules == [
        "La legumbre cuenta como 1/2 intercambio de proteína",
        "Grasas: solo en una de las dos (comida o cena)",
    ]


def test_a_missing_sheet_is_reported_by_name() -> None:
    workbook = nutritionist_workbook()
    del workbook["Intercambios"]

    assert problems_of(workbook) == [Problem("Intercambios", None, "falta la hoja")]


def test_a_wrong_header_is_reported_with_its_cell() -> None:
    workbook = nutritionist_workbook()
    workbook["Semana 1"]["B3"] = "Almuerzo"

    assert problems_of(workbook) == [
        Problem("Semana 1", "B3", "se esperaba la cabecera «Comida» y hay «Almuerzo»")
    ]


def test_headers_are_compared_without_case_or_accents() -> None:
    workbook = nutritionist_workbook()
    workbook["Semana 1"]["A3"] = "MENU"

    assert read(workbook).number == 99


def test_a_missing_menu_is_reported_by_number() -> None:
    workbook = nutritionist_workbook()
    workbook["Semana 1"].delete_rows(7)  # la fila del menú 4

    assert problems_of(workbook) == [Problem("Semana 1", None, "falta el menú 4")]


def test_a_repeated_menu_and_a_missing_dinner_are_reported_together() -> None:
    workbook = nutritionist_workbook()
    week = workbook["Semana 1"]
    week["A5"] = 1  # el menú 2 pasa a ser otro menú 1
    week["C8"] = None  # el menú 5 se queda sin cena

    # Todos los problemas a la vez, cada uno en su celda (como la Form Request).
    assert problems_of(workbook) == [
        Problem("Semana 1", "A5", "el menú 1 está repetido"),
        Problem("Semana 1", "C8", "falta la cena del menú 5"),
        Problem("Semana 1", None, "falta el menú 2"),
    ]


def test_a_title_without_number_is_reported_in_a1() -> None:
    workbook = nutritionist_workbook()
    workbook["Semana 1"]["A1"] = "Semana 1"

    [problem] = problems_of(workbook)
    assert (problem.sheet, problem.cell) == ("Semana 1", "A1")


def test_options_without_choose_n_are_reported_instead_of_guessed() -> None:
    workbook = nutritionist_workbook()
    workbook["Desayunos y snacks"]["B5"] = "1 pieza de fruta"

    [problem] = problems_of(workbook)
    assert (problem.sheet, problem.cell) == ("Desayunos y snacks", "B5")
    assert "cuántas elegir" in problem.message


def test_an_unreadable_exchange_is_reported_with_its_cell() -> None:
    workbook = nutritionist_workbook()
    workbook["Intercambios"]["C4"] = "Arroz (1/3 vaso) – pan"
    workbook["Intercambios"]["B6"] = "Lácteos"

    assert [(p.sheet, p.cell) for p in problems_of(workbook)] == [
        ("Intercambios", "C4"),
        ("Intercambios", "B6"),
    ]


def test_a_file_that_is_not_an_excel_is_rejected() -> None:
    with pytest.raises(InvalidWorkbookError) as raised:
        read_nutritionist_plan(BytesIO(b"esto no es un Excel"))

    assert raised.value.problems == [
        Problem(None, None, "El fichero no es un Excel (.xlsx) válido.")
    ]
