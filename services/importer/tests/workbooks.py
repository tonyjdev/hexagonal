"""Excel ficticios de la nutricionista para los tests.

``nutritionist_workbook()`` construye en memoria un libro con la MISMA
estructura que el Excel real (hojas, títulos, cabeceras en la fila 3, celdas
combinadas, viñetas ``•``, intercambios separados por guiones...), pero con
contenido inventado: el Excel real del usuario no se versiona (datos de salud,
docs/CONVENCIONES.md, apartado 6). Cada test parte de este libro y lo estropea
a propósito (borra una hoja, cambia una cabecera...) para probar un caso.

Ejecutado como script, guarda el mismo libro en ``tests/fixtures/`` para las
pruebas manuales con ``curl`` (``uv run poe fixtures``).

CONCEPTO: *test data builder*
Un constructor de datos de prueba válidos por defecto, que cada test modifica
solo en lo que le importa. Es la idea de los *Object Mother* del núcleo
(``NutritionPlanMother``), aplicada a un fichero.
"""

from io import BytesIO
from pathlib import Path

from openpyxl import Workbook

FIXTURE = Path(__file__).parent / "fixtures" / "plan-nutricionista-ficticio.xlsx"

MENUS: list[tuple[int, str, str]] = [
    (
        1,
        "Garbanzos (1/2 bote) con espinacas + 1 huevo cocido",
        "Merluza al horno (1 filete) con judías verdes",
    ),
    (
        2,
        "Arroz (1/3 vaso en crudo) con verduras y pollo (2 filetes)",
        "Crema de calabaza + tortilla francesa (2 huevos)",
    ),
    (
        3,
        "Ensalada de lentejas (1/2 bote) con pimiento y cebolla",
        "Salmón a la plancha (1 filete) con espárragos",
    ),
    (
        4,
        "Pasta integral (3/4 vaso en crudo) con atún y tomate",
        "Revuelto de champiñones (2 huevos) + pan (1 rebanada)",
    ),
    (
        5,
        "Patata asada (1 mediana) con pavo (4 filetes) y ensalada",
        "Sopa de verduras + queso fresco (1 tarrina)",
    ),
    (
        6,
        "Quinoa (1/3 vaso en crudo) salteada con gambas y brócoli",
        "Ensalada verde con caballa (1 lata) y nueces",
    ),
]


def nutritionist_workbook() -> Workbook:
    """Un Excel de la nutricionista válido, ficticio, con la estructura del real."""
    workbook = Workbook()

    week = workbook.active
    assert week is not None
    week.title = "Semana 1"
    week["A1"] = "Plan nutricional 99 - Semana 1"
    week.merge_cells("A1:C1")
    week.append([])
    week.append(["Menú", "Comida", "Cena"])
    for number, lunch, dinner in MENUS:
        week.append([number, lunch, dinner])

    fixed = workbook.create_sheet("Desayunos y snacks")
    fixed["A1"] = "Desayunos y comidas intermedias"
    fixed.merge_cells("A1:C1")
    fixed.append([])
    fixed.append(["Momento", "Menú / indicaciones", "Opciones"])
    fixed.append(["Desayuno", "Café con leche desnatada + 2 tostadas integrales con tomate", None])
    fixed.append(
        [
            "Media mañana",
            "1 pieza de fruta y elegir 2 de las opciones",
            "• 1 yogur natural\n• 15 g de almendras\n• 2 lonchas de pavo",
        ]
    )
    fixed.append(["Media tarde", "Infusión + 1 puñado de nueces", None])

    exchanges = workbook.create_sheet("Intercambios")
    exchanges["A1"] = "Intercambios y pautas"
    exchanges.merge_cells("A1:C1")
    exchanges.append([])
    exchanges.append(["Momento", "Categoría", "Equivalencias / pauta"])
    protein = (
        "Huevo (2 uds) – pollo (2 filetes) – pescado blanco (1 filete grande). "
        "La legumbre cuenta como 1/2 intercambio de proteína"
    )
    exchanges.append(["Comida", "Hidratos de carbono", "Arroz (1/3 vaso) – pan (2 rebanadas)"])
    exchanges.append(["Comida", "Proteínas", protein])
    exchanges.append(["Comida", "Vegetales", "> 1/2 plato"])
    exchanges.append(["Cena", "Proteínas", protein])
    exchanges.append(["Cena", "Vegetales", "> 1/2 plato"])
    exchanges.append(
        [
            "Comida o cena",
            "Grasas (solo en una de las dos)",
            "1/2 aguacate – 1 puñado de nueces – 6-7 aceitunas",
        ]
    )

    # La hoja «Receta» existe en el Excel real; el lector la ignora.
    recipe = workbook.create_sheet("Receta")
    recipe["A1"] = "Crema de calabaza (2 raciones)"
    recipe.merge_cells("A1:B1")
    recipe.append([])
    recipe.append(["Sección", "Contenido"])
    recipe.append(["Ingredientes", "• 1/2 calabaza\n• 1 puerro\n• Sal"])

    return workbook


def as_file(workbook: Workbook) -> BytesIO:
    """El libro guardado como ``.xlsx`` en memoria, listo para leer o subir.

    CONCEPTO: ``BytesIO``
    Un «fichero» que vive en memoria: se escribe y se lee como uno de disco,
    sin tocar el disco. El ``php://memory`` de PHP.
    """
    buffer = BytesIO()
    workbook.save(buffer)
    buffer.seek(0)
    return buffer


# CONCEPTO: ``if __name__ == "__main__"``
# Este bloque solo se ejecuta al lanzar el fichero como script
# (``python tests/workbooks.py``), no cuando un test lo importa.
if __name__ == "__main__":
    FIXTURE.parent.mkdir(exist_ok=True)
    nutritionist_workbook().save(FIXTURE)
    print(f"Guardado {FIXTURE}")
