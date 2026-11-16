"""Lector del Excel de la semana 2: de un ``.xlsx`` a ``SelfWeek``.

La semana 2 la elabora el usuario, en un Excel con dos hojas:

- «Detalle»: una fila por comida, con las cabeceras en la fila 1: «Día»
  (8-14), «Tipo» (Comida o Cena), «Plato» (el nombre corto) e
  «Ingredientes / cantidades» (separados por comas). El día 13, el sábado
  libre, tiene sus dos filas sin plato.
- «Resumen»: una cuadrícula con los días en la fila 1 («Día 8» … «Día 14»,
  columnas B a H) y la comida y la cena en las filas 2 y 3, solo con el
  nombre de cada plato.

DECISIÓN (ADR 0023): «Detalle» es la fuente y «Resumen» se comprueba.
Las dos hojas están escritas a mano (no hay fórmulas), así que «Resumen» es
una segunda copia de los nombres que puede quedarse desfasada si se corrige
un plato en una hoja y no en la otra. Si no coinciden, el lector no puede
saber cuál es la buena, y quedarse con una en silencio escondería el error:
se responde 422 señalando las dos celdas.

CONCEPTO: dos formatos, dos lectores
Este lector y el de la nutricionista (``nutritionist``) no se conocen: cada
uno sabe de su documento y devuelve su modelo. Comparten la fontanería de
``importer.reading.workbook`` (abrir el libro, leer celdas, reunir
problemas). Es la misma idea que en el núcleo hexagonal, donde
``MenuWeek::fromNutritionistMenus()`` y ``fromSelfDays()`` traducen cada
formato por separado (HEXM-0013).

CAPA: lógica del servicio (``importer.reading``): no sabe nada de HTTP.
"""

from dataclasses import dataclass
from typing import BinaryIO

from openpyxl.utils import get_column_letter

from importer.reading import text
from importer.reading.models import Ingredient, SelfDay, SelfDish, SelfWeek
from importer.reading.workbook import (
    WorkbookReading,
    cell_text,
    data_rows,
    open_workbook,
    whole_number,
)

DETAIL_SHEET = "Detalle"
SUMMARY_SHEET = "Resumen"

DAYS = range(8, 15)
FREE_DAY = 13

DETAIL_HEADERS = {
    "A1": "Día",
    "B1": "Tipo",
    "C1": "Plato",
    "D1": "Ingredientes / cantidades",
}
FIRST_DETAIL_ROW = 2

# En «Resumen», el día 8 está en la columna B (la 2) y el 14 en la H (la 8).
SUMMARY_ROWS: dict[text.SelfMeal, int] = {"lunch": 2, "dinner": 3}
SUMMARY_HEADERS = {
    **{f"{get_column_letter(day - 6)}1": f"Día {day}" for day in DAYS},
    "A2": "Comida",
    "A3": "Cena",
}

# Para los mensajes: «falta la comida del día 9».
MEAL_NAMES: dict[text.SelfMeal, str] = {"lunch": "comida", "dinner": "cena"}

type _Key = tuple[int, text.SelfMeal]


def read_self_week(source: BinaryIO) -> SelfWeek:
    """Lee el Excel de la semana 2 y devuelve sus 7 días (del 8 al 14, el 13 vacío).

    Si el Excel no tiene la forma esperada, lanza ``InvalidWorkbookError`` con
    TODOS los problemas encontrados, de las dos hojas.
    """
    reading = _Reading(open_workbook(source))

    dishes = reading.detail()
    reading.compare_summary(dishes)

    reading.raise_if_any()

    # Sin problemas, «Detalle» tiene la comida y la cena de cada día salvo el
    # 13, que no tiene ninguna: ``dishes.get()`` da ``None`` solo para el 13.
    return SelfWeek(
        self_week=[
            SelfDay(
                day=day,
                lunch=_dish_of(dishes.get((day, "lunch"))),
                dinner=_dish_of(dishes.get((day, "dinner"))),
            )
            for day in DAYS
        ]
    )


@dataclass(frozen=True, slots=True)
class _Detail:
    """Un plato leído de «Detalle» y la celda de su nombre (para los mensajes de «Resumen»)."""

    dish: SelfDish
    cell: str


def _dish_of(detail: _Detail | None) -> SelfDish | None:
    return detail.dish if detail is not None else None


@dataclass
class _Reading(WorkbookReading):
    """La lectura del Excel de la semana 2: «Detalle» primero y «Resumen» después."""

    def detail(self) -> dict[_Key, _Detail]:
        """Los platos de «Detalle», por día y comida.

        Además de leer cada fila, comprueba la forma de la semana para poder
        decir en qué celda está el problema: días del 8 al 14, tipo «Comida» o
        «Cena», ninguna comida repetida, todas presentes salvo las del 13, y el
        13 sin plato. Laravel volvería a comprobar las reglas de la semana
        (``MenuWeek``, ``MenuDay``), pero sin poder señalar la celda.

        Un plato sin ingredientes es válido (regla 13 del dominio): sale con
        ``ingredients: []``.
        """
        sheet = self.sheet(DETAIL_SHEET, DETAIL_HEADERS)
        if sheet is None:
            return {}
        dishes: dict[_Key, _Detail] = {}
        seen: set[_Key] = set()
        for row in data_rows(sheet, FIRST_DETAIL_ROW, "ABCD"):
            day = whole_number(sheet, f"A{row}")
            label = cell_text(sheet, f"B{row}")
            name = cell_text(sheet, f"C{row}")
            ingredients = cell_text(sheet, f"D{row}")

            # ``day is None`` va aparte para que mypy sepa que, después, es un ``int``:
            # ``in`` no le sirve para descartar el ``None`` (sí lo hace ``is None``).
            if day is None or day not in DAYS:
                self.problem(DETAIL_SHEET, f"A{row}", "el día debe ser del 8 al 14")
                continue
            if label is None:
                self.problem(DETAIL_SHEET, f"B{row}", "falta el tipo: «Comida» o «Cena»")
                continue
            try:
                meal = text.self_meal(label)
            except text.UnreadableTextError as error:
                self.problem(DETAIL_SHEET, f"B{row}", str(error))
                continue
            if (day, meal) in seen:
                self.problem(
                    DETAIL_SHEET, f"A{row}", f"la {MEAL_NAMES[meal]} del día {day} está repetida"
                )
                continue
            seen.add((day, meal))

            if day == FREE_DAY:
                if name is not None or ingredients is not None:
                    self.problem(
                        DETAIL_SHEET,
                        f"C{row}" if name is not None else f"D{row}",
                        "el día 13 es el sábado libre: no puede tener plato",
                    )
                continue
            if name is None:
                self.problem(
                    DETAIL_SHEET,
                    f"C{row}",
                    f"falta el plato de la {MEAL_NAMES[meal]} del día {day}",
                )
                continue
            dishes[(day, meal)] = _Detail(
                dish=SelfDish(
                    name=name,
                    ingredients=[
                        Ingredient(name=piece) for piece in text.ingredients(ingredients or "")
                    ],
                ),
                cell=f"C{row}",
            )

        for day in DAYS:
            if day == FREE_DAY:
                continue
            for meal, meal_name in MEAL_NAMES.items():
                if (day, meal) not in seen:
                    self.problem(DETAIL_SHEET, None, f"falta la {meal_name} del día {day}")
        return dishes

    def compare_summary(self, dishes: dict[_Key, _Detail]) -> None:
        """Comprueba que «Resumen» diga los mismos platos que «Detalle».

        Se comparan normalizados (sin mayúsculas, tildes ni espacios de más):
        «Pasta con salmón» y «pasta con salmon» son el mismo plato. Una comida
        que «Detalle» no ha podido leer no se compara: su problema ya está
        anotado.
        """
        sheet = self.sheet(SUMMARY_SHEET, SUMMARY_HEADERS)
        if sheet is None:
            return
        for day in DAYS:
            column = get_column_letter(day - 6)
            for meal, row in SUMMARY_ROWS.items():
                cell = f"{column}{row}"
                found = cell_text(sheet, cell)
                if day == FREE_DAY:
                    if found is not None:
                        self.problem(
                            SUMMARY_SHEET,
                            cell,
                            "el día 13 es el sábado libre: no puede tener plato",
                        )
                    continue
                detail = dishes.get((day, meal))
                if detail is None:
                    continue
                expected = detail.dish.name
                if found is None:
                    self.problem(
                        SUMMARY_SHEET,
                        cell,
                        f"falta el plato; en la hoja «{DETAIL_SHEET}» "
                        f"(celda {detail.cell}) es «{expected}»",
                    )
                elif text.normalize(found) != text.normalize(expected):
                    self.problem(
                        SUMMARY_SHEET,
                        cell,
                        f"el plato «{found}» no coincide con el de la hoja «{DETAIL_SHEET}» "
                        f"(celda {detail.cell}: «{expected}»)",
                    )
