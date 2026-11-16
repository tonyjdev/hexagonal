"""Lo común a todos los lectores de Excel: abrir el libro, leer celdas y reunir problemas.

Hay dos formatos de documento (el Excel de la nutricionista y el de la
semana 2), cada uno con su lector (``nutritionist`` y ``self_week``) y su
modelo. Lo que no depende del formato vive aquí: abrir un ``.xlsx`` subido,
leer el texto o el número de una celda, recorrer las filas con datos y
comprobar las cabeceras de una hoja anotando los problemas.

CONCEPTO: extraer lo común sin mezclar los formatos
Con un solo lector, estas funciones eran privadas suyas. Con el segundo
formato aparece la tentación de un «lector genérico» que entienda los dos con
opciones; aquí se hace lo contrario: cada lector conserva su conocimiento del
documento (qué hojas, qué columnas, qué significa cada texto) y solo se
comparte la fontanería. Es la regla de «tres veces antes de abstraer»
aplicada a medias: con dos lectores se extrae lo que es idéntico, no lo que
se parece.

CAPA: lógica del servicio (``importer.reading``): no sabe nada de HTTP.
"""

from collections.abc import Iterator, Mapping
from dataclasses import dataclass, field
from typing import BinaryIO
from zipfile import BadZipFile

from openpyxl import load_workbook
from openpyxl.utils.exceptions import InvalidFileException
from openpyxl.workbook.workbook import Workbook
from openpyxl.worksheet.worksheet import Worksheet

from importer.reading import text
from importer.reading.problems import InvalidWorkbookError, Problem


def open_workbook(source: BinaryIO) -> Workbook:
    """Abre el libro, o lanza ``InvalidWorkbookError`` si el fichero no es un ``.xlsx``.

    ``data_only=True`` devuelve el valor calculado de las fórmulas, no la
    fórmula. Un ``.xlsx`` es un ZIP con XML dentro: si no lo es, openpyxl
    lanza alguna de estas excepciones según dónde falle.

    CONCEPTO: ``BinaryIO``
    El tipo de «algo que se lee como un fichero binario»: un fichero en disco,
    un ``BytesIO`` en memoria o el fichero temporal de una subida. Es un
    tipado por comportamiento (como depender de una interfaz en PHP), así que
    al lector le da igual de dónde venga.
    """
    try:
        return load_workbook(source, data_only=True)
    except (BadZipFile, InvalidFileException, KeyError, OSError, ValueError) as error:
        raise InvalidWorkbookError.of(
            None, None, "El fichero no es un Excel (.xlsx) válido."
        ) from error


@dataclass
class WorkbookReading:
    """El estado de una lectura: el libro y los problemas encontrados hasta ahora.

    Los lectores heredan de esta clase y añaden un método por parte del
    documento. Cada método anota sus problemas en ``self.problems`` en lugar
    de lanzar una excepción, para poder seguir y reunirlos todos (ver
    ``importer.reading.problems``); al final, el lector lanza una única
    ``InvalidWorkbookError`` con ``raise_if_any()``.

    CONCEPTO: ``field(default_factory=list)``
    Un valor por defecto que se crea de nuevo para cada instancia. Escribir
    ``problems: list[Problem] = []`` compartiría la MISMA lista entre todas las
    instancias (un error clásico de Python); en PHP no pasa porque los arrays
    se copian por valor.

    CONCEPTO: herencia de ``dataclass``
    Una ``dataclass`` que hereda de otra suma sus atributos a los del padre y
    Python genera un constructor con todos. Aquí los lectores no añaden
    atributos: heredan para reutilizar ``problem()`` y ``sheet()``, como una
    clase base abstracta en PHP.
    """

    workbook: Workbook
    problems: list[Problem] = field(default_factory=list)

    def problem(self, sheet: str, cell: str | None, message: str) -> None:
        self.problems.append(Problem(sheet, cell, message))

    def raise_if_any(self) -> None:
        if self.problems:
            raise InvalidWorkbookError(self.problems)

    def sheet(self, name: str, headers: Mapping[str, str]) -> Worksheet | None:
        """La hoja con sus cabeceras comprobadas, o ``None`` (con su problema anotado).

        ``headers`` dice qué texto se espera en cada celda de cabecera
        (``{"A3": "Menú", ...}``): normalmente una fila, pero también puede
        ser una columna de etiquetas («Comida» y «Cena» en la hoja «Resumen»).
        Se comparan normalizadas (sin mayúsculas ni tildes): «MENÚ» vale como
        «Menú». Si falta una cabecera, la hoja entera no es fiable (las
        columnas podrían estar cambiadas), así que no se leen sus filas.
        """
        if name not in self.workbook.sheetnames:
            self.problem(name, None, "falta la hoja")
            return None
        sheet = self.workbook[name]
        headers_ok = True
        for cell, expected in headers.items():
            found = cell_text(sheet, cell)
            if found is None or text.normalize(found) != text.normalize(expected):
                shown = f"«{found}»" if found else "una celda vacía"
                self.problem(name, cell, f"se esperaba la cabecera «{expected}» y hay {shown}")
                headers_ok = False
        return sheet if headers_ok else None


# --- lectura de celdas ----------------------------------------------------


def cell_text(sheet: Worksheet, cell: str) -> str | None:
    """El texto de una celda sin espacios alrededor, o ``None`` si está vacía."""
    value = sheet[cell].value
    if value is None:
        return None
    stripped = str(value).strip()
    return stripped or None


def whole_number(sheet: Worksheet, cell: str) -> int | None:
    """El número entero de una celda, o ``None`` si no lo es.

    Excel guarda los números como ``int`` o ``float`` (``1.0``), y a veces
    como texto (``"1"``) si la celda tiene formato de texto: se aceptan los
    tres, siempre que sea un entero. Qué enteros valen (menús 1-6, días 8-14)
    lo decide cada lector.

    CONCEPTO: ``match`` (*pattern matching*)
    Como un ``match`` de PHP 8, pero comparando también tipos y formas:
    ``case int()`` encaja si el valor es un ``int``. ``bool`` va antes porque
    en Python ``True`` también es un ``int``.
    """
    match sheet[cell].value:
        case bool():
            return None
        case int() as number:
            return number
        case float() as number if number.is_integer():
            return int(number)
        case str() as raw if raw.strip().isdigit():
            return int(raw)
        case _:
            return None


def data_rows(sheet: Worksheet, first_row: int, columns: str) -> Iterator[int]:
    """Los números de fila con datos, desde ``first_row`` hasta la primera fila vacía.

    Una fila está vacía si todas sus ``columns`` (``"ABC"``) lo están.

    CONCEPTO: generador (``yield``)
    Una función con ``yield`` devuelve los valores de uno en uno, a medida que
    se piden en el ``for``, sin construir la lista entera. Es lo mismo que un
    generador de PHP (``yield`` dentro de una función que devuelve
    ``Generator``).
    """
    for row in range(first_row, sheet.max_row + 1):
        if all(cell_text(sheet, f"{column}{row}") is None for column in columns):
            return
        yield row
