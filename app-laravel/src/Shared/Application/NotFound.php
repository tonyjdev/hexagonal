<?php

declare(strict_types=1);

namespace Shared\Application;

/**
 * NotFound: clase base de "lo que buscas no existe", para todos los contextos.
 *
 * CONCEPTO: "no encontrado" es parte del contrato de un caso de uso
 * Cuando se consulta una receta por su id, hay dos resultados posibles: la
 * receta o "no existe". El segundo no es un fallo técnico ni una regla de
 * negocio incumplida: es una RESPUESTA normal de la consulta. El caso de uso
 * la expresa lanzando una excepción concreta (RecipeNotFound), que quien lo
 * llama puede esperar y tratar.
 *
 * DECISIÓN: no hereda de Shared\Domain\DomainException (HEXM-0011)
 * Una DomainException significa "el negocio no permite esto", y la API la
 * traduce a 422. "No existe" es otra cosa, y la API la traduce a 404. Al
 * tener su propia clase base, un único manejador global puede convertir
 * cualquier "no encontrado" de cualquier contexto (receta, plan...) en 404
 * sin conocer las excepciones concretas, igual que hace con DomainException.
 *
 * DECISIÓN: capa de aplicación, no dominio
 * Ninguna regla del recetario dice "una receta tiene que existir": que no se
 * encuentre es el resultado de BUSCAR, y buscar es lo que hace un caso de uso
 * a través del puerto (RecipeRepository::find() devuelve null). Por eso vive
 * en Shared\Application, el shared kernel de la capa de aplicación.
 *
 * Por qué extiende \RuntimeException: es la excepción nativa para errores
 * que solo se conocen al ejecutar (no se puede saber de antemano si un id
 * existe). Al ser PHP nativo, la lista blanca del núcleo la permite.
 *
 * Su mensaje está en español, porque llega al usuario final.
 *
 * CAPA: Aplicación compartida (Shared\Application). No depende de ningún contexto.
 */
abstract class NotFound extends \RuntimeException {}
