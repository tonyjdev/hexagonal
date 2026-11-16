"""Lector del Excel de la nutricionista: de un ``.xlsx`` a ``NutritionistPlan``.

El Excel es el que sale del paso manual desde el PDF, con estas hojas (la
fila 3 lleva las cabeceras y los datos empiezan en la 4):

- «Semana 1»: título en A1 («Plan nutricional 27 - Semana 1») y una fila por
  menú: «Menú» (1-6), «Comida» y «Cena».
- «Desayunos y snacks»: una fila por pauta fija: «Momento» (Desayuno, Media
  mañana, Media tarde), «Menú / indicaciones» (la parte fija, con el
  «elegir N» dentro del texto) y «Opciones» (separadas por ``•``).
- «Intercambios»: una fila por momento y categoría: «Momento» (Comida, Cena,
  Comida o cena), «Categoría» y «Equivalencias / pauta» (los intercambios
  separados por guiones, y a veces una frase con una regla al final).
- «Receta»: se ignora en esta tarea (llevarla al recetario sería otra).

Las decisiones de traducción están en el ADR 0022.

CONCEPTO: openpyxl
La librería estándar de Python para leer y escribir ``.xlsx`` (lo que en PHP
sería PhpSpreadsheet). Un ``Workbook`` (libro) tiene hojas (``Worksheet``),
que se piden por su nombre (``workbook["Semana 1"]``), y cada hoja tiene
celdas que se piden por su coordenada (``sheet["A1"].value``). El valor llega
ya con su tipo de Python: ``str``, ``int``, ``float``, ``datetime`` o ``None``
si la celda está vacía.

CAPA: lógica del servicio (``importer.reading``): no sabe nada de HTTP.
"""

from collections.abc import Iterator
from dataclasses import dataclass
from typing import BinaryIO

from openpyxl.worksheet.worksheet import Worksheet

from importer.reading import text
from importer.reading.models import (
    Exchange,
    FixedMeal,
    NutritionistMenu,
    NutritionistPlan,
    OptionGroup,
)
from importer.reading.workbook import (
    WorkbookReading,
    cell_text,
    data_rows,
    open_workbook,
    whole_number,
)

WEEK_SHEET = "Semana 1"
FIXED_MEALS_SHEET = "Desayunos y snacks"
EXCHANGES_SHEET = "Intercambios"

TITLE_CELL = "A1"
HEADER_ROW = 3
FIRST_DATA_ROW = HEADER_ROW + 1

# Las cabeceras que se esperan en la fila 3 de cada hoja, por columna. Se
# comparan normalizadas (sin mayúsculas ni tildes): «MENÚ» vale como «Menú».
HEADERS: dict[str, dict[str, str]] = {
    WEEK_SHEET: {"A": "Menú", "B": "Comida", "C": "Cena"},
    FIXED_MEALS_SHEET: {"A": "Momento", "B": "Menú / indicaciones", "C": "Opciones"},
    EXCHANGES_SHEET: {"A": "Momento", "B": "Categoría", "C": "Equivalencias / pauta"},
}

MENUS = range(1, 7)


def read_nutritionist_plan(source: BinaryIO) -> NutritionistPlan:
    """Lee el Excel de la nutricionista y devuelve la parte del plan que contiene.

    ``source`` es el fichero abierto en modo binario (un ``open(..., "rb")`` o
    el fichero de una subida HTTP). Si el Excel no tiene la forma esperada,
    lanza ``InvalidWorkbookError`` con TODOS los problemas encontrados.
    """
    reading = _Reading(open_workbook(source))

    number = reading.plan_number()
    nutritionist_week = reading.nutritionist_week()
    fixed_meals = reading.fixed_meals()
    exchanges, exchange_rules = reading.exchanges()

    reading.raise_if_any()

    # Si no hay problemas, todos los datos están: el ``assert`` se lo cuenta a
    # mypy (``number`` deja de ser ``int | None``), como un ``@var`` en PHPStan,
    # pero comprobado al ejecutar.
    assert number is not None
    return NutritionistPlan(
        number=number,
        fixed_meals=fixed_meals,
        exchanges=exchanges,
        exchange_rules=exchange_rules,
        nutritionist_week=nutritionist_week,
    )


@dataclass
class _Reading(WorkbookReading):
    """La lectura del Excel de la nutricionista: un método por hoja.

    Lo común (el libro, los problemas, comprobar cabeceras) lo hereda de
    ``WorkbookReading``. Lo que no se puede leer se devuelve vacío (o ``None``)
    con su problema anotado.
    """

    def _sheet(self, name: str) -> Worksheet | None:
        cells = {f"{column}{HEADER_ROW}": header for column, header in HEADERS[name].items()}
        return self.sheet(name, cells)

    # --- «Semana 1» --------------------------------------------------------

    def plan_number(self) -> int | None:
        if WEEK_SHEET not in self.workbook.sheetnames:
            return None  # el problema «falta la hoja» lo anota nutritionist_week()
        title = cell_text(self.workbook[WEEK_SHEET], TITLE_CELL)
        if title is None:
            self.problem(WEEK_SHEET, TITLE_CELL, "falta el título con el número de plan")
            return None
        try:
            return text.plan_number(title)
        except text.UnreadableTextError as error:
            self.problem(WEEK_SHEET, TITLE_CELL, str(error))
            return None

    def nutritionist_week(self) -> list[NutritionistMenu]:
        """Los 6 menús de la semana 1, en el orden del Excel.

        Además de leer cada fila, comprueba que estén los menús del 1 al 6 sin
        repetir: si faltara uno, Laravel diría «la semana debe tener 6 días»,
        pero aquí se puede decir cuál falta y en qué hoja.
        """
        sheet = self._sheet(WEEK_SHEET)
        if sheet is None:
            return []
        menus: list[NutritionistMenu] = []
        seen: set[int] = set()
        for row in _data_rows(sheet):
            number = _menu_number(sheet, f"A{row}")
            lunch, dinner = cell_text(sheet, f"B{row}"), cell_text(sheet, f"C{row}")
            if number is None:
                self.problem(WEEK_SHEET, f"A{row}", "el número de menú debe ser del 1 al 6")
                continue
            if number in seen:
                self.problem(WEEK_SHEET, f"A{row}", f"el menú {number} está repetido")
                continue
            seen.add(number)
            if lunch is None:
                self.problem(WEEK_SHEET, f"B{row}", f"falta la comida del menú {number}")
            if dinner is None:
                self.problem(WEEK_SHEET, f"C{row}", f"falta la cena del menú {number}")
            if lunch is not None and dinner is not None:
                menus.append(NutritionistMenu(menu=number, lunch=lunch, dinner=dinner))
        for missing in sorted(set(MENUS) - seen):
            self.problem(WEEK_SHEET, None, f"falta el menú {missing}")
        return menus

    # --- «Desayunos y snacks» ---------------------------------------------

    def fixed_meals(self) -> list[FixedMeal]:
        """Las pautas fijas: la descripción tal cual y, si hay opciones, un grupo «elige N».

        La descripción se conserva entera, con el «elegir N» dentro: es el
        texto de la nutricionista, y quitarle trozos podría dejarlo cojo.
        """
        sheet = self._sheet(FIXED_MEALS_SHEET)
        if sheet is None:
            return []
        meals: list[FixedMeal] = []
        for row in _data_rows(sheet):
            label = cell_text(sheet, f"A{row}")
            description = cell_text(sheet, f"B{row}")
            options_cell = cell_text(sheet, f"C{row}")
            if label is None:
                self.problem(FIXED_MEALS_SHEET, f"A{row}", "falta el momento de la pauta")
                continue
            try:
                meal_type = text.fixed_meal_type(label)
            except text.UnreadableTextError as error:
                self.problem(FIXED_MEALS_SHEET, f"A{row}", str(error))
                continue

            groups: list[OptionGroup] = []
            if options_cell is not None:
                choose = text.choose_count(description or "")
                if choose is None:
                    self.problem(
                        FIXED_MEALS_SHEET,
                        f"B{row}",
                        "hay opciones pero no se dice cuántas elegir: "
                        "se espera «elegir N» en las indicaciones",
                    )
                    continue
                groups.append(OptionGroup(choose=choose, options=text.options(options_cell)))
            meals.append(
                FixedMeal(meal_type=meal_type, description=description, option_groups=groups)
            )
        return meals

    # --- «Intercambios» ---------------------------------------------------

    def exchanges(self) -> tuple[list[Exchange], list[str]]:
        """Los intercambios por categoría y las reglas del plan.

        - La columna «Momento» no se lleva al contrato: el dominio no la modela
          (los intercambios son comunes al plan). Por eso una categoría que
          aparece en la comida y en la cena con los mismos intercambios se
          queda una sola vez (se quitan los repetidos, conservando el orden).
        - Las reglas salen de dos sitios: las frases después de la lista
          («La legumbre cuenta como…») y la nota dentro de la categoría
          («Grasas (solo en una de las dos)»), que se escribe con su momento.
          También se quitan las repetidas.

        CONCEPTO: ``dict`` como conjunto ordenado
        ``dict.fromkeys(lista)`` quita los repetidos conservando el orden de
        aparición (los ``dict`` de Python lo conservan desde 3.7). Un ``set``
        también los quitaría, pero perdiendo el orden.
        """
        sheet = self._sheet(EXCHANGES_SHEET)
        if sheet is None:
            return [], []
        exchanges: list[Exchange] = []
        rules: list[str] = []
        for row in _data_rows(sheet):
            moment = cell_text(sheet, f"A{row}")
            label = cell_text(sheet, f"B{row}")
            cell = cell_text(sheet, f"C{row}")
            if label is None:
                self.problem(EXCHANGES_SHEET, f"B{row}", "falta la categoría")
                continue
            try:
                category, name, note = text.exchange_category(label)
            except text.UnreadableTextError as error:
                self.problem(EXCHANGES_SHEET, f"B{row}", str(error))
                continue
            if note is not None:
                rules.append(f"{name}: {note}" + (f" ({moment.lower()})" if moment else ""))
            if cell is None:
                self.problem(EXCHANGES_SHEET, f"C{row}", f"faltan las equivalencias de {name}")
                continue
            items, notes = text.exchange_cell(cell)
            rules.extend(notes)
            for item in items:
                try:
                    food, serving = text.exchange_item(item, name)
                except text.UnreadableTextError as error:
                    self.problem(EXCHANGES_SHEET, f"C{row}", str(error))
                    continue
                exchanges.append(Exchange(category=category, food=food, serving=serving))
        return list(dict.fromkeys(exchanges)), list(dict.fromkeys(rules))


# --- lectura de celdas ----------------------------------------------------


def _menu_number(sheet: Worksheet, cell: str) -> int | None:
    """El número de menú (1-6), o ``None`` si no lo es."""
    number = whole_number(sheet, cell)
    return number if number in MENUS else None


def _data_rows(sheet: Worksheet) -> Iterator[int]:
    """Las filas con datos, desde la 4 hasta la primera fila vacía."""
    return data_rows(sheet, FIRST_DATA_ROW, "ABC")
