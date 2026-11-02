<?php

declare(strict_types=1);

namespace Shared\Domain;

/**
 * Uuid: un identificador universal único (RFC 9562), generado en versión 7.
 *
 * CONCEPTO: shared kernel
 * Hasta HEXM-0012 este código vivía dentro de Recipes\Domain\RecipeId. El
 * ADR 0005 dejó apuntado que se extraería cuando otro contexto lo necesitara,
 * y ese momento ha llegado: MealPlanning necesita NutritionPlanId. Extraerlo
 * con UN solo uso habría sido una abstracción prematura; con dos usos que
 * repetirían exactamente las mismas 20 líneas de bits, compensa.
 *
 * Por qué ESTO sí va al shared kernel (y Quantity no, ver HEXM-0012): un UUID
 * es pura técnica, no tiene significado de negocio. No hay riesgo de que
 * "un UUID" signifique una cosa en Recipes y otra en MealPlanning. En cambio,
 * una cantidad de ingrediente sí es un concepto del negocio que cada contexto
 * puede querer modelar a su manera.
 *
 * CONCEPTO: composición frente a herencia
 * Los identificadores del negocio (RecipeId, NutritionPlanId) TIENEN un Uuid
 * dentro; no SON un Uuid (no heredan de esta clase). Así siguen siendo tipos
 * distintos: PHPStan no deja pasar un NutritionPlanId donde se espera un
 * RecipeId, aunque por dentro los dos sean un UUID. Y cada uno conserva su
 * propia excepción con un mensaje que habla del negocio ("no es un
 * identificador de receta válido").
 *
 * Por eso Uuid no lanza excepciones: tryFromString() devuelve null si el
 * texto no es un UUID, y el identificador que lo envuelve decide qué error
 * de dominio lanzar.
 *
 * DECISIONES (ADR 0005): el dominio genera el identificador, en versión 7
 * (ordenado por fecha de creación) y con PHP nativo, sin librería.
 *
 * CAPA: Dominio compartido (Shared\Domain). Solo PHP nativo.
 */
final readonly class Uuid
{
    /**
     * Formato canónico de un UUID: 8-4-4-4-12 caracteres hexadecimales, con
     * la versión (1 a 8) y la variante RFC (8, 9, a o b) en su sitio.
     *
     * Al leer se acepta cualquier versión, no solo la 7: un identificador
     * válido puede venir de fuera (por ejemplo, generado por PostgreSQL), y
     * lo que importa al leerlo es que esté bien formado.
     */
    private const string PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    private function __construct(private string $value) {}

    /**
     * Genera un UUID nuevo, versión 7.
     *
     * Estructura de los 128 bits (16 bytes):
     *
     *   | 48 bits: milisegundos Unix | 4: versión (7) | 12: aleatorios |
     *   | 2: variante (10) | 62: aleatorios |
     *
     * Límite conocido: dos UUID generados en el mismo milisegundo no tienen
     * un orden garantizado entre sí (solo los separa la parte aleatoria). Para
     * datos que crea una persona a mano es irrelevante.
     */
    public static function v7(): self
    {
        $milliseconds = (int) floor(microtime(true) * 1000);

        // pack('J') escribe un entero de 64 bits en big-endian (8 bytes); los
        // 2 primeros son ceros mientras no lleguemos al año 10889, y nos
        // quedamos con los 6 últimos (48 bits).
        $bytes = substr(pack('J', $milliseconds), 2).random_bytes(10);

        // Byte 6: los 4 bits altos son la versión → 0111 (7).
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x70);
        // Byte 8: los 2 bits altos son la variante RFC → 10.
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        $hex = bin2hex($bytes);

        return new self(sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        ));
    }

    /**
     * Lee un UUID que ya existe, o devuelve null si el texto no lo es.
     *
     * Se normaliza a minúsculas, que es la forma canónica, para que equals()
     * no dependa de cómo vino escrito.
     *
     * CONCEPTO: "try" + nullable en vez de excepción
     * El prefijo try sigue la convención de PHP (Unit::tryFrom() en los
     * enums): devuelve null en lugar de lanzar. Quien llama está obligado
     * por el tipo ?Uuid (y por PHPStan) a tratar el caso inválido, y elige
     * su propia excepción de dominio.
     */
    public static function tryFromString(string $value): ?self
    {
        $normalized = strtolower(trim($value));

        if (preg_match(self::PATTERN, $normalized) !== 1) {
            return null;
        }

        return new self($normalized);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
