"""Servicio importer: lee los Excel de los planes nutricionales y los devuelve como JSON.

Es el primer servicio Python del proyecto (nivel 7, bloque B). Para Laravel es
solo un adaptador detrás de un puerto (``PlanReader``, HEXM-0031): el núcleo
hexagonal no sabe que existe.

CONCEPTO: paquete de Python
Una carpeta con ``__init__.py`` es un *paquete*: se puede importar
(``import importer``) y agrupa módulos (ficheros ``.py``). Es lo más parecido a
un *namespace* de PHP con su autoload PSR-4: ``importer.api.health`` ↔
``Importer\\Api\\Health``. Este fichero se ejecuta al importar el paquete; aquí
solo lo documenta.
"""
