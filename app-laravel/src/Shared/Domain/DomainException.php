<?php

declare(strict_types=1);

namespace Shared\Domain;

/**
 * DomainException: clase base de todos los errores de negocio del núcleo.
 *
 * CONCEPTO: excepción de dominio
 * Cuando alguien intenta crear algo que el negocio no permite (una cantidad de
 * cero gramos, una receta con cero raciones...), el dominio lo rechaza
 * lanzando una excepción que HABLA EL LENGUAJE DEL NEGOCIO. Su mensaje está en
 * español porque es el que acabará viendo el usuario final ("La cantidad debe
 * ser mayor que cero"), no un detalle técnico para el programador.
 *
 * CONCEPTO: shared kernel
 * Vive en Shared\Domain porque es un acuerdo común a todos los contextos:
 * Recipes y MealPlanning heredan de ella (InvalidQuantity, InvalidServings...).
 * Así, en el nivel 2, un único manejador de excepciones de Laravel podrá
 * convertir CUALQUIER error de dominio en una respuesta HTTP 422 sin conocer
 * cada excepción concreta: le basta con capturar esta clase base.
 *
 * Por qué abstracta: nunca se lanza "un error de dominio" genérico, siempre
 * uno concreto que diga qué regla se ha incumplido.
 *
 * Por qué extiende \DomainException de PHP: es la excepción nativa pensada
 * justo para esto ("el valor no pertenece al dominio válido"). Al ser PHP
 * nativo, la lista blanca de dependencias del núcleo la permite (ADR 0004).
 *
 * CAPA: Dominio compartido (Shared\Domain). No depende de ningún contexto.
 */
abstract class DomainException extends \DomainException {}
