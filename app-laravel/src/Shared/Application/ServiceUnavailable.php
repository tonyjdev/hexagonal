<?php

declare(strict_types=1);

namespace Shared\Application;

/**
 * ServiceUnavailable: clase base de «un sistema externo del que dependo no
 * responde», para todos los contextos.
 *
 * CONCEPTO: un fallo de infraestructura contado en el idioma del núcleo
 * Cuando un puerto habla con algo de fuera (el servicio Python que lee los
 * Excel, HEXM-0031), puede fallar por razones que no son del negocio ni del
 * usuario: el servicio está caído, tarda demasiado o responde algo que no se
 * entiende. El adaptador NO deja escapar sus excepciones técnicas
 * (ConnectionException de Laravel, un JSON roto...), porque el núcleo no
 * sabe qué son. Las traduce a una subclase de esta (PlanReaderUnavailable),
 * que el caso de uso puede esperar y que dice lo que importa: «ahora no se
 * puede, inténtalo más tarde».
 *
 * DECISIÓN: su propia clase base, como NotFound (ADR 0024)
 * No es una DomainException (422): el usuario no ha hecho nada mal y repetir
 * la petición más tarde puede funcionar. Tampoco es un NotFound (404). Con
 * una clase base propia, el manejador global de bootstrap/app.php convierte
 * cualquier «no disponible» de cualquier contexto en HTTP 503 (Service
 * Unavailable) sin conocer las excepciones concretas.
 *
 * DECISIÓN: SÍ se registra en el log
 * Al contrario que DomainException y NotFound (respuestas normales), esto es
 * un fallo del sistema: alguien tiene que enterarse de que el importador
 * está caído. Por eso no se añade a dontReport().
 *
 * Extiende \RuntimeException (PHP nativo, permitido en el núcleo): solo se
 * sabe al ejecutar. El mensaje está en español, para el usuario final; el
 * detalle técnico viaja en la excepción anterior ($previous), que es lo que
 * se lee en el log.
 *
 * CAPA: Aplicación compartida (Shared\Application). No depende de ningún contexto.
 */
abstract class ServiceUnavailable extends \RuntimeException {}
