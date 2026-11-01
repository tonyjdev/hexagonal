<?php

declare(strict_types=1);

namespace Recipes\Domain;

/**
 * RecipeId: identificador de una receta.
 *
 * CONCEPTO: identificador como Value Object
 * La receta (HEXM-0006) será una ENTIDAD: tiene identidad y sigue siendo "la
 * misma receta" aunque cambie su nombre o sus pasos. Esa identidad es este
 * value object. Envolver el UUID en un tipo propio, en vez de pasar strings,
 * impide confundir un RecipeId con otro identificador cualquiera, y
 * PHPStan lo comprueba en cada firma de método.
 *
 * Es además LO ÚNICO que el contexto MealPlanning sabrá de una receta (un
 * Dish podrá referenciar un RecipeId, nivel 6).
 *
 * DECISIÓN: el dominio genera el identificador (UUID), no la base de datos
 * Con un autoincremental, la receta no tiene identidad hasta que se guarda,
 * y el dominio dependería de la base de datos para algo tan básico. Con un
 * UUID, la receta nace con su identidad, se puede crear y probar en memoria
 * y el repositorio solo la guarda. Ver docs/adr/0005-identificadores-uuid-v7-en-el-dominio.md.
 *
 * DECISIÓN: UUID versión 7, generado con PHP nativo (sin librería)
 * Un UUID v7 (RFC 9562) empieza por la marca de tiempo en milisegundos, así
 * que los identificadores salen ORDENADOS por fecha de creación. En
 * PostgreSQL eso significa que el índice de la clave primaria crece por el
 * final en vez de insertarse en posiciones aleatorias (como con v4), y
 * funciona mejor. Generarlo son pocas líneas de PHP nativo, así que no hace
 * falta autorizar ramsey/uuid ni symfony/uid en la lista blanca (ADR 0004).
 *
 * CAPA: Dominio (Recipes\Domain). Solo PHP nativo.
 */
final readonly class RecipeId
{
    /**
     * Formato canónico de un UUID: 8-4-4-4-12 caracteres hexadecimales, con
     * la versión (1 a 8) y la variante RFC (8, 9, a o b) en su sitio.
     *
     * fromString() acepta cualquier versión, no solo la 7: un identificador
     * válido puede venir de fuera (por ejemplo, generado por PostgreSQL), y
     * lo que importa al leerlo es que esté bien formado.
     */
    private const string PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    private function __construct(private string $value) {}

    /**
     * Genera un identificador nuevo (UUID v7).
     *
     * Estructura de los 128 bits (16 bytes):
     *
     *   | 48 bits: milisegundos Unix | 4: versión (7) | 12: aleatorios |
     *   | 2: variante (10) | 62: aleatorios |
     *
     * Límite conocido: dos UUID generados en el mismo milisegundo no tienen
     * un orden garantizado entre sí (solo los separa la parte aleatoria). Para
     * recetas que crea una persona a mano es irrelevante.
     */
    public static function generate(): self
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
     * Reconstruye un identificador que ya existe (al leerlo de la base de
     * datos o de una petición HTTP). Se normaliza a minúsculas, que es la
     * forma canónica, para que equals() no dependa de cómo vino escrito.
     */
    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));

        if (preg_match(self::PATTERN, $normalized) !== 1) {
            throw InvalidRecipeId::malformed($value);
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
