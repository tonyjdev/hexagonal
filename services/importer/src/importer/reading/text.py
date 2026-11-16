"""Traducción del texto de una celda: funciones puras, sin Excel ni HTTP.

Cada función recibe el texto de una celda tal como lo escribió la
nutricionista y devuelve un dato del contrato, o lanza ``UnreadableTextError`` con
un mensaje en español. No saben en qué hoja ni en qué celda están: eso lo
añade el lector (``nutritionist``) al convertir el error en un ``Problem``.

CONCEPTO: función pura
Su resultado solo depende de sus argumentos y no tiene efectos secundarios (no
lee ficheros, no guarda nada). Se prueban con una línea por caso, sin preparar
un Excel. Es la misma idea que separar el dominio de la infraestructura: la
parte difícil (entender el texto) no depende de la parte ruidosa (abrir el
fichero).

CONCEPTO: expresiones regulares (módulo ``re``)
Las mismas que ``preg_match()`` en PHP, sin los delimitadores: ``re.search``
busca en cualquier parte, ``re.fullmatch`` exige que encaje el texto entero y
``re.split`` parte por un patrón. ``(?P<nombre>...)`` es un grupo con nombre
(en PHP, ``(?<nombre>...)``) y ``re.IGNORECASE`` es el modificador ``/i``.

Las decisiones de traducción (por qué se parte por ``•``, cómo se reconoce
«elegir N», qué se hace con un intercambio sin alimento...) están en el
ADR 0022.
"""

import re
import unicodedata

from importer.reading.models import ExchangeCategory, MealType


class UnreadableTextError(ValueError):
    """El texto de una celda no tiene el formato esperado. El mensaje va en español."""


def normalize(text: str) -> str:
    """Texto para comparar: minúsculas, sin tildes y con los espacios colapsados.

    «Media Mañana» y «media  manana» se comparan igual. Solo sirve para
    reconocer cabeceras y etiquetas: lo que se devuelve en el contrato se deja
    siempre como estaba escrito.

    CONCEPTO: normalización Unicode (NFKD)
    Descompone «ñ» en «n» + la tilde combinada «◌̃»; después se quitan las
    marcas combinadas (``unicodedata.combining``). En PHP se haría con
    ``Normalizer::normalize()`` de la extensión intl.
    """
    decomposed = unicodedata.normalize("NFKD", text)
    without_marks = "".join(char for char in decomposed if not unicodedata.combining(char))
    return " ".join(without_marks.lower().split())


# --- Hoja «Semana 1» ------------------------------------------------------

_PLAN_NUMBER = re.compile(r"plan\s+nutricional\s+(?P<number>\d+)", re.IGNORECASE)


def plan_number(title: str) -> int:
    """El número de plan del título: «Plan nutricional 27 - Semana 1» → 27."""
    match = _PLAN_NUMBER.search(title)
    if match is None:
        raise UnreadableTextError(
            f"no se encuentra el número de plan en «{title}»: "
            "se espera un título como «Plan nutricional 27 - Semana 1»"
        )
    return int(match["number"])


# --- Hoja «Desayunos y snacks» --------------------------------------------

# CONCEPTO: dict (diccionario)
# Un array asociativo de PHP: clave → valor. Las claves están normalizadas
# (``normalize``) para no depender de mayúsculas ni tildes.
_MEAL_TYPES: dict[str, MealType] = {
    "desayuno": MealType.BREAKFAST,
    "media manana": MealType.MID_MORNING,
    "media tarde": MealType.AFTERNOON_SNACK,
    "merienda": MealType.AFTERNOON_SNACK,
}


def fixed_meal_type(label: str) -> MealType:
    """El tipo de pauta fija de la columna «Momento»: «Media mañana» → ``mid_morning``."""
    meal_type = _MEAL_TYPES.get(normalize(label))
    if meal_type is None:
        raise UnreadableTextError(
            f"«{label}» no es una pauta fija: se espera «Desayuno», «Media mañana» o «Media tarde»"
        )
    return meal_type


def options(cell: str) -> list[str]:
    """Las opciones de una celda con viñetas: «• A\\n• B» → ["A", "B"].

    Cada ``•`` empieza una opción; se quitan los espacios y los saltos de línea
    de alrededor y se descarta lo vacío. Lo que haya antes de la primera
    viñeta también cuenta como opción (por si una celda empieza sin ella).

    CONCEPTO: *list comprehension*
    ``[expresión for x in lista if condición]`` construye una lista nueva en
    una línea: es un ``array_filter`` + ``array_map`` de PHP.
    """
    return [option.strip() for option in cell.split("•") if option.strip()]


_NUMBER_WORDS: dict[str, int] = {
    "un": 1,
    "una": 1,
    "uno": 1,
    "dos": 2,
    "tres": 3,
    "cuatro": 4,
    "cinco": 5,
    "seis": 6,
    "siete": 7,
    "ocho": 8,
    "nueve": 9,
    "diez": 10,
}

# «elegir 2», «elige dos», «escoger una»... El número, en cifra o en palabra.
_CHOOSE = re.compile(r"\b(?:elegir|elige|escoger|escoge)\s+(?P<count>\d+|[a-z]+)\b")


def choose_count(description: str) -> int | None:
    """El N de «elegir N de las opciones» dentro de una descripción, o ``None``.

    La nutricionista no lo pone en una columna: va dentro del texto de la
    pauta («… y elegir 2 de las opciones»). Se acepta en cifra o en palabra.
    Si no aparece, devuelve ``None`` y el lector decide (con opciones y sin N,
    es un problema: adivinar N sería inventarse el plan).
    """
    match = _CHOOSE.search(normalize(description))
    if match is None:
        return None
    count = match["count"]
    if count.isdigit():
        return int(count)
    return _NUMBER_WORDS.get(count)


# --- Hoja «Intercambios» --------------------------------------------------

# Prefijo normalizado de la etiqueta → categoría. Por prefijo porque la
# etiqueta puede llevar más texto («Hidratos de carbono», «Grasas (solo…)»).
_CATEGORIES: tuple[tuple[str, ExchangeCategory], ...] = (
    ("hidratos", ExchangeCategory.CARBOHYDRATES),
    ("proteina", ExchangeCategory.PROTEIN),
    ("vegetal", ExchangeCategory.VEGETABLES),
    ("verdura", ExchangeCategory.VEGETABLES),
    ("grasa", ExchangeCategory.FAT),
)

# Un paréntesis al final de la etiqueta: «Grasas (solo en una de las dos)».
_TRAILING_NOTE = re.compile(r"^(?P<label>.*?)\s*\((?P<note>[^()]*)\)\s*$")


def exchange_category(label: str) -> tuple[ExchangeCategory, str, str | None]:
    """La categoría de la columna «Categoría», su nombre y su nota si la lleva.

    «Grasas (solo en una de las dos)» → (``fat``, "Grasas", "solo en una de las dos").
    La nota es una regla del plan que la nutricionista ha escrito dentro de la
    categoría; el lector la convierte en una regla de ``exchange_rules``.

    CONCEPTO: tupla
    ``tuple[A, B, C]`` es una lista de tamaño y tipos fijos, inmutable. Sirve
    para devolver varios valores a la vez (en PHP, un ``array{...}`` con
    *destructuring*: ``[$category, $name, $note] = ...``).
    """
    match = _TRAILING_NOTE.match(label)
    name, note = (match["label"], match["note"].strip()) if match else (label.strip(), None)
    normalized = normalize(name)
    for prefix, category in _CATEGORIES:
        if normalized.startswith(prefix):
            return category, name, note or None
    raise UnreadableTextError(
        f"«{label}» no es una categoría de intercambio: "
        "se espera «Hidratos de carbono», «Proteínas», «Vegetales» o «Grasas»"
    )


# Una frase que empieza después de un punto: «… (100 g). La legumbre cuenta…».
# Solo si tras el punto hay espacio y mayúscula, para no partir «aprox.)».
_SENTENCE_BREAK = re.compile(r"\.\s+(?=[A-ZÁÉÍÓÚÑ¿¡])")

# Los intercambios se separan con un guion entre espacios (–, — o -). Con los
# espacios obligatorios, «6-7 aceitunas» no se parte.
_ITEM_SEPARATOR = re.compile(r"\s+[–—-]\s+")


def exchange_cell(cell: str) -> tuple[list[str], list[str]]:
    """Separa una celda de equivalencias en sus intercambios y sus notas.

    «Huevo (2 uds) – carne (4 filetes). La legumbre cuenta como 1/2 intercambio»
    → (["Huevo (2 uds)", "carne (4 filetes)"], ["La legumbre cuenta como 1/2 intercambio"]).

    Las frases que van después de la lista son reglas del plan: el lector las
    lleva a ``exchange_rules``.
    """
    items_part, *notes = _SENTENCE_BREAK.split(cell.strip())
    items = [item.strip() for item in _ITEM_SEPARATOR.split(items_part.rstrip(". "))]
    return [item for item in items if item], [note.strip() for note in notes if note.strip()]


_QUANTITY_START = r"[\d½¼¾⅓⅔]"

# «alimento (ración)»: la ración empieza por una cantidad.
_FOOD_THEN_SERVING = re.compile(rf"^(?P<food>[^()]+?)\s*\((?P<serving>{_QUANTITY_START}[^()]*)\)$")

# «ración de alimento»: empieza por una cantidad («1 puñado de frutos secos»,
# «1/2 aguacate», «6-7 aceitunas»).
_SERVING_THEN_FOOD = re.compile(rf"^(?P<amount>{_QUANTITY_START}[\d½¼¾⅓⅔/.,-]*)\s+(?P<rest>.+)$")

# Una ración sin alimento: «> 1/2 plato», «más de medio plato».
_SERVING_ONLY = re.compile(r"^(?:[<>≤≥]|m[aá]s de |menos de )", re.IGNORECASE)


def exchange_item(item: str, category_name: str) -> tuple[str, str]:
    """Un intercambio de la lista → (alimento, ración), como estaban escritos.

    Se reconocen tres formas (ADR 0022):

    1. «alimento (ración)»: «arroz (1/3 vaso)» → ("arroz", "1/3 vaso").
    2. «ración de alimento»: «1 puñado de frutos secos» → ("frutos secos", "1 puñado");
       sin «de», la cantidad es la primera palabra: «1/2 aguacate» → ("aguacate", "1/2").
    3. Una ración sin alimento: «> 1/2 plato» → (nombre de la categoría, "> 1/2 plato").
       El dominio exige alimento; el más fiel es la propia categoría («Vegetales»).
    """
    if match := _FOOD_THEN_SERVING.match(item):
        return match["food"].strip(), match["serving"].strip()

    # CONCEPTO: operador morsa (``:=``)
    # Asigna y evalúa a la vez: ``if match := ...`` guarda el resultado en
    # ``match`` y entra en el ``if`` si no es ``None``. Es el
    # ``if ($match = ...)`` de PHP.
    if match := _SERVING_THEN_FOOD.match(item):
        amount, rest = match["amount"], match["rest"]
        unit, separator, food = rest.partition(" de ")
        if separator:
            return food.strip(), f"{amount} {unit.strip()}"
        return rest.strip(), amount

    if _SERVING_ONLY.match(item):
        return category_name, item

    raise UnreadableTextError(
        f"no se reconoce el intercambio «{item}»: "
        "se espera «alimento (ración)» o «ración de alimento»"
    )
