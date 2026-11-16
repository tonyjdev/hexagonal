"""Modelos del contrato: lo que devuelve la lectura de los Excel de un plan.

Tienen la misma forma que el contrato de ``POST /api/nutrition-plans``
(ADR 0014), repartido entre los dos documentos de un plan:

- ``NutritionistPlan``, el Excel de la nutricionista (HEXM-0029): ``number``,
  ``fixed_meals``, ``exchanges``, ``exchange_rules`` y ``nutritionist_week``.
- ``SelfWeek``, el Excel de la semana 2 que elabora el usuario (HEXM-0030):
  ``self_week``.

Falta ``starts_on``: la fecha de inicio no está en ningún Excel, la dará el
usuario al confirmar la importación. Laravel juntará las piezas (HEXM-0031 y
siguientes).

CONCEPTO: modelos de Pydantic como contrato
En PHP este papel lo reparten dos piezas: un DTO (la forma de los datos, con
sus tipos) y una *Form Request* (las reglas de validación). En Pydantic son la
misma clase: los *type hints* dan la forma y ``Field(...)`` añade las reglas
(mínimos, longitudes). Al construir un modelo, Pydantic valida; si algo no
cuadra, lanza ``ValidationError``. Y FastAPI lo usa para serializar la
respuesta y describirla en el contrato OpenAPI (``/openapi.json``), que será
la base de los tests de contrato con Laravel (HEXM-0031).

Qué se valida aquí y qué no: la FORMA del contrato (tipos, que un número sea
positivo, que una lista no esté vacía cuando no puede estarlo). Las reglas del
negocio (6 días por semana, «elige N» no mayor que las opciones, intercambios
con alimento y ración...) son del dominio de Laravel, que las comprueba venga
el plan de donde venga. Es la misma frontera que en la *Form Request*
(ADR 0007), ahora en Python.
"""

from enum import StrEnum

from pydantic import BaseModel, ConfigDict, Field


class ContractModel(BaseModel):
    """Base de los modelos del contrato.

    - ``frozen=True``: inmutables una vez creados (como un ``readonly class``).
    - ``extra="forbid"``: un campo que no existe en el modelo es un error, no
      se ignora en silencio. Con un contrato entre dos servicios, una errata
      (``fixed_meal`` en vez de ``fixed_meals``) debe fallar enseguida.

    CONCEPTO: herencia de configuración
    Los modelos que heredan de este reciben su ``model_config``: se escribe
    una vez y vale para todos, como una clase base abstracta en PHP.
    """

    model_config = ConfigDict(frozen=True, extra="forbid")


class MealType(StrEnum):
    """Tipo de una pauta fija, con los valores del ``MealType`` del dominio.

    CONCEPTO: ``StrEnum``
    Un enum cuyos casos SON textos (``MealType.BREAKFAST == "breakfast"``): el
    equivalente a un *backed enum* de PHP (``enum MealType: string``). Pydantic
    lo serializa con su valor, así que en el JSON sale ``"breakfast"``.

    Solo tiene las pautas fijas: comida y cena no son pautas fijas (regla 15),
    y el Excel no las trae en la hoja «Desayunos y snacks».
    """

    BREAKFAST = "breakfast"
    MID_MORNING = "mid_morning"
    AFTERNOON_SNACK = "afternoon_snack"


class ExchangeCategory(StrEnum):
    """Categoría de un intercambio, con los valores del ``ExchangeCategory`` del dominio."""

    CARBOHYDRATES = "carbohydrates"
    PROTEIN = "protein"
    VEGETABLES = "vegetables"
    FAT = "fat"


class OptionGroup(ContractModel):
    """Un grupo «elegir N de las siguientes».

    ``Field(ge=1)`` es «mayor o igual que 1» (como la regla ``min:1`` de
    Laravel) y ``min_length=1`` exige al menos una opción. Que N no supere el
    número de opciones es una regla del dominio (``OptionGroup::choose()``).
    """

    choose: int = Field(ge=1)
    options: list[str] = Field(min_length=1)


class FixedMeal(ContractModel):
    """Una pauta fija: desayuno, media mañana o media tarde.

    ``description`` es la parte fija en texto libre (``None`` si no hay) y
    ``option_groups``, sus grupos de opciones (``[]`` si no hay).

    CONCEPTO: ``str | None``
    Un tipo opcional: puede ser un texto o ``None``. Es el ``?string`` de PHP.
    """

    meal_type: MealType
    description: str | None
    option_groups: list[OptionGroup]


class Exchange(ContractModel):
    """Un intercambio: categoría + alimento + ración, todo como en el Excel."""

    category: ExchangeCategory
    food: str = Field(min_length=1)
    serving: str = Field(min_length=1)


class NutritionistMenu(ContractModel):
    """Un menú de la semana 1: su número (1-6), la comida y la cena en texto libre.

    El número va dentro de cada elemento (y no como clave de un objeto) para
    que el orden y los duplicados sean visibles (ADR 0014). ``ge=1, le=6``
    acota el número: el menú 6 es el domingo, eso lo decide el dominio.
    """

    menu: int = Field(ge=1, le=6)
    lunch: str = Field(min_length=1)
    dinner: str = Field(min_length=1)


class NutritionistPlan(ContractModel):
    """El Excel de la nutricionista leído: número, pautas fijas, intercambios y semana 1.

    CONCEPTO: ``list[Modelo]``
    Una lista tipada de modelos: Pydantic valida cada elemento. Es el
    ``list<FixedMeal>`` de un ``@var`` de PHPStan, pero comprobado al ejecutar.
    """

    number: int = Field(ge=1)
    fixed_meals: list[FixedMeal]
    exchanges: list[Exchange]
    exchange_rules: list[str]
    nutritionist_week: list[NutritionistMenu] = Field(min_length=6, max_length=6)


# --- Excel de la semana 2 (HEXM-0030) --------------------------------------


class Ingredient(ContractModel):
    """Un ingrediente de un plato de la semana 2, con su texto entero.

    ``name`` lleva el trozo tal como está en el Excel, con la cantidad dentro
    («⅓ vaso de arroz en crudo»), y ``quantity`` va siempre a ``None``: separar
    la cantidad del ingrediente con fiabilidad no es trabajo de un lector
    determinista, sino de un LLM (nivel 9, ``IngredientExtractor``). El campo
    se envía igualmente porque es parte del contrato (ADR 0014) y así, cuando
    llegue el nivel 9, solo cambia su valor, no la forma (ADR 0023).
    """

    name: str = Field(min_length=1)
    quantity: str | None = None


class SelfDish(ContractModel):
    """Un plato de la semana 2: nombre corto e ingredientes (puede no tener ninguno)."""

    name: str = Field(min_length=1)
    ingredients: list[Ingredient]


class SelfDay(ContractModel):
    """Un día de la semana 2 (8-14) con su comida y su cena.

    El 13 es el sábado libre y va con las dos a ``None``; el resto, con las
    dos. Que el sábado no tenga menú es una regla del dominio (``MenuDay``),
    pero el lector ya la comprueba para poder decir en qué celda está el
    problema.
    """

    day: int = Field(ge=8, le=14)
    lunch: SelfDish | None
    dinner: SelfDish | None


class SelfWeek(ContractModel):
    """El Excel de la semana 2 leído: la parte ``self_week`` del contrato del alta.

    Siempre lleva los 7 días, del 8 al 14 y en orden, con el 13 vacío.
    """

    self_week: list[SelfDay] = Field(min_length=7, max_length=7)
