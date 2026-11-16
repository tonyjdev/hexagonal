"""Problemas de forma de un Excel: qué está mal y dónde (hoja y celda).

CONCEPTO: reunir todos los errores en lugar de parar en el primero
Un Excel con la forma equivocada suele tener varios problemas a la vez. Si el
lector parase en el primero, el usuario corregiría uno, volvería a subir el
fichero y se encontraría el siguiente. Por eso el lector va anotando los
problemas en una lista y, al final, si hay alguno, lanza una única excepción
con todos. Es lo que hace la *Form Request* de Laravel: devuelve todos los
errores de forma de una vez, cada uno con la ruta del campo; aquí la «ruta»
es la hoja y la celda.
"""

from dataclasses import dataclass


@dataclass(frozen=True, slots=True)
class Problem:
    """Un problema de forma: el mensaje en español y dónde está.

    ``cell`` es ``None`` cuando el problema es de la hoja entera (que falte), y
    ``sheet`` es ``None`` cuando es del fichero entero (que no sea un Excel).

    CONCEPTO: ``dataclass``
    Una clase de datos: Python genera el constructor, la comparación (``==``
    por valor) y la representación a partir de los atributos anotados.
    ``frozen=True`` la hace inmutable y ``slots=True`` ahorra memoria. Es lo
    más parecido a un ``final readonly class`` de PHP con *constructor
    promotion*. No es un modelo de Pydantic porque no hace falta validar nada:
    lo crea el propio lector, no viene de fuera.
    """

    sheet: str | None
    cell: str | None
    message: str

    def located_message(self) -> str:
        """El mensaje con su ubicación delante: «Hoja «Semana 1», celda A1: …»."""
        if self.sheet is None:
            return self.message
        where = f"Hoja «{self.sheet}»" + (f", celda {self.cell}" if self.cell else "")
        return f"{where}: {self.message}"


class InvalidWorkbookError(Exception):
    """El Excel no tiene la forma esperada. Lleva todos los problemas encontrados.

    CONCEPTO: excepción propia
    Igual que las ``DomainException`` del núcleo de Laravel: un tipo propio
    para un error concreto, que la capa HTTP traduce a 422 sin que el lector
    sepa nada de HTTP (``importer.api``). En Python la convención (PEP 8, y
    la regla N818 de ruff) es terminar el nombre en ``Error``; en el núcleo de
    PHP se nombran sin sufijo (``InvalidQuantity``). Cada lenguaje, su estilo.
    """

    def __init__(self, problems: list[Problem]) -> None:
        self.problems = problems
        super().__init__(
            "El Excel no tiene la forma esperada:\n"
            + "\n".join(f"- {problem.located_message()}" for problem in problems)
        )

    @classmethod
    def of(cls, sheet: str | None, cell: str | None, message: str) -> InvalidWorkbookError:
        """Atajo para un único problema (por ejemplo, un fichero que no es un Excel).

        CONCEPTO: ``@classmethod``
        Un método que recibe la clase (``cls``) en lugar de una instancia: es
        el ``static`` de PHP que se usa para los constructores con nombre
        (``InvalidQuantity::notPositive()``).
        """
        return cls([Problem(sheet, cell, message)])
