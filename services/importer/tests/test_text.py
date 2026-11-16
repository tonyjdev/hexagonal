"""Tests de la traducción del texto de las celdas (``importer.reading.text``).

Son funciones puras: cada caso es una línea, sin Excel ni HTTP.

CONCEPTO: ``pytest.mark.parametrize``
Ejecuta el mismo test con varios datos: una fila por caso, cada una se ve
como un test aparte en el informe. Es el ``->with([...])`` (*datasets*) de Pest.
"""

import pytest

from importer.reading import text
from importer.reading.models import ExchangeCategory, MealType


def test_the_plan_number_comes_from_the_title() -> None:
    assert text.plan_number("Plan nutricional 27 - Semana 1") == 27
    assert text.plan_number("PLAN NUTRICIONAL 3") == 3


def test_a_title_without_a_plan_number_is_unreadable() -> None:
    # CONCEPTO: ``pytest.raises``
    # Comprueba que el bloque lanza esa excepción (con ``match``, que su
    # mensaje encaja con el patrón). Es el ``->toThrow()`` de Pest.
    with pytest.raises(text.UnreadableTextError, match="no se encuentra el número de plan"):
        text.plan_number("Semana 1")


@pytest.mark.parametrize(
    ("label", "expected"),
    [
        ("Desayuno", MealType.BREAKFAST),
        ("Media mañana", MealType.MID_MORNING),
        ("MEDIA MANANA", MealType.MID_MORNING),
        ("Media tarde", MealType.AFTERNOON_SNACK),
    ],
)
def test_the_moment_of_a_fixed_meal_ignores_case_and_accents(
    label: str, expected: MealType
) -> None:
    assert text.fixed_meal_type(label) == expected


def test_lunch_is_not_a_fixed_meal() -> None:
    with pytest.raises(text.UnreadableTextError, match="no es una pauta fija"):
        text.fixed_meal_type("Comida")


def test_each_bullet_starts_an_option() -> None:
    assert text.options("• 1 yogur natural\n• 15 g de almendras\n•  \n• 2 lonchas") == [
        "1 yogur natural",
        "15 g de almendras",
        "2 lonchas",
    ]


@pytest.mark.parametrize(
    ("description", "expected"),
    [
        ("Tostada con aguacate y elegir 2 de las opciones", 2),
        ("Elige dos de las siguientes", 2),
        ("escoger una", 1),
        ("Yogur natural con avena", None),
    ],
)
def test_choose_n_is_read_from_the_description(description: str, expected: int | None) -> None:
    assert text.choose_count(description) == expected


@pytest.mark.parametrize(
    ("label", "expected"),
    [
        ("Hidratos de carbono", (ExchangeCategory.CARBOHYDRATES, "Hidratos de carbono", None)),
        ("Proteínas", (ExchangeCategory.PROTEIN, "Proteínas", None)),
        ("Vegetales", (ExchangeCategory.VEGETABLES, "Vegetales", None)),
        (
            "Grasas (solo en una de las dos)",
            (ExchangeCategory.FAT, "Grasas", "solo en una de las dos"),
        ),
    ],
)
def test_the_category_label_may_carry_a_rule_in_brackets(
    label: str, expected: tuple[ExchangeCategory, str, str | None]
) -> None:
    assert text.exchange_category(label) == expected


def test_an_unknown_category_is_unreadable() -> None:
    with pytest.raises(text.UnreadableTextError, match="no es una categoría de intercambio"):
        text.exchange_category("Lácteos")


def test_an_exchange_cell_splits_items_by_spaced_dashes_and_keeps_trailing_sentences() -> None:
    items, notes = text.exchange_cell(
        "Huevo (2 uds) – lácteos (100 g aprox.) - 6-7 aceitunas. La legumbre cuenta como 1/2"
    )

    # «6-7» no se parte (el guion no lleva espacios) y «aprox.)» no corta la frase.
    assert items == ["Huevo (2 uds)", "lácteos (100 g aprox.)", "6-7 aceitunas"]
    assert notes == ["La legumbre cuenta como 1/2"]


@pytest.mark.parametrize(
    ("item", "expected"),
    [
        # 1. «alimento (ración)»
        ("arroz (1/3 vaso)", ("arroz", "1/3 vaso")),
        ("pechuga de pavo (3 lonchas)", ("pechuga de pavo", "3 lonchas")),
        # 2. «ración de alimento»
        ("1 puñado de frutos secos", ("frutos secos", "1 puñado")),
        ("2 cdas soperas de queso (+curado)", ("queso (+curado)", "2 cdas soperas")),
        ("1/2 aguacate", ("aguacate", "1/2")),
        ("6-7 aceitunas", ("aceitunas", "6-7")),
        # 3. una ración sin alimento: el alimento es la categoría
        ("> 1/2 plato", ("Vegetales", "> 1/2 plato")),
    ],
)
def test_an_exchange_item_is_split_into_food_and_serving(
    item: str, expected: tuple[str, str]
) -> None:
    assert text.exchange_item(item, "Vegetales") == expected


def test_an_exchange_without_serving_is_unreadable() -> None:
    with pytest.raises(text.UnreadableTextError, match="no se reconoce el intercambio «pan»"):
        text.exchange_item("pan", "Hidratos de carbono")
