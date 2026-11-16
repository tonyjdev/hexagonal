"""Lectura de los Excel de los planes: de un ``.xlsx`` a los modelos del contrato.

CAPA: lógica del servicio, sin HTTP. Este paquete no importa nada de FastAPI
(ADR 0021): recibe un fichero y devuelve un modelo de Pydantic, o una lista de
problemas con su hoja y su celda. Así se prueba con pytest sin pasar por HTTP,
y la capa web (``importer.api``) solo traduce HTTP ↔ lectura.

Módulos:

- ``models``: el contrato de salida (las partes del JSON de
  ``POST /api/nutrition-plans`` que trae cada Excel, ADR 0014).
- ``problems``: los problemas de forma de un Excel y la excepción que los lleva.
- ``text``: funciones puras que traducen el texto de una celda (el número del
  plan, las opciones con ``•``, los intercambios, los ingredientes...). Las
  decisiones de traducción están en los ADR 0022 y 0023.
- ``workbook``: lo común a los lectores (abrir el libro, leer celdas,
  comprobar cabeceras y reunir problemas).
- ``nutritionist``: el lector del Excel de la nutricionista (HEXM-0029).
- ``self_week``: el lector del Excel de la semana 2 (HEXM-0030).
"""
