<?php

declare(strict_types=1);

namespace Recipes\Domain;

/**
 * Quantity: cantidad de un ingrediente, es decir, un número y una unidad
 * ("½ bote", "200 gramos", "1 ½ cucharada sopera").
 *
 * CONCEPTO: Value Object
 * No tiene identidad: dos cantidades de ½ bote son intercambiables, da igual
 * cuál sea "cuál". Por eso:
 *   - es inmutable (`final readonly`): para "cambiarla" se crea otra;
 *   - se compara por valor con equals(), no con `===` (que compararía si son
 *     el mismo objeto en memoria).
 *
 * CONCEPTO: always-valid domain (validación en el constructor)
 * El constructor es privado y solo se llega a él a través de los
 * constructores con nombre, que validan antes. Resultado: NO PUEDE EXISTIR
 * una Quantity de cero, negativa o con denominador cero. Cualquier código que
 * reciba una Quantity puede confiar en ella sin volver a comprobarla.
 *
 * CONCEPTO: constructores con nombre (named constructors)
 *   Quantity::of(200, Unit::Gram)              → 200 g
 *   Quantity::fraction(1, 2, Unit::Jar)        → ½ bote
 *   Quantity::fromString('1 ½', Unit::Glass)   → 1 ½ vasos
 * Cada uno cuenta CÓMO se crea la cantidad, cosa que un `new Quantity(...)`
 * con cuatro parámetros no explicaría.
 *
 * DECISIÓN: fracción exacta con numerador/denominador (no float)
 * Los planes de la nutricionista usan fracciones: "½ bote", "⅓ de taza",
 * "¾ de vaso". Un float no puede representar ⅓ (0.333... se corta en algún
 * decimal) y arrastra errores de redondeo: en PHP, 0.1 + 0.2 === 0.3 es
 * false. Guardar dos enteros sí es exacto:
 *   ½ = 1/2 · ⅓ = 1/3 · ¾ = 3/4 · 1,5 = 3/2 · 200 = 200/1
 * Además, la fracción se guarda SIMPLIFICADA (2/4 se guarda como 1/2), para
 * que equals() pueda comparar campo a campo. Esto será clave para escalar
 * raciones o sumar cantidades en la lista de la compra (bloque C) sin perder
 * precisión. Se descartó un string decimal con bcmath: tampoco representa ⅓.
 *
 * DECISIÓN: "al gusto" y "una pizca" NO son una Quantity
 * Una Quantity siempre tiene un número positivo. Una cantidad sin número es
 * la AUSENCIA de cantidad y se modelará en Ingredient con una cantidad
 * opcional (HEXM-0006). Así esta clase no necesita casos especiales ni
 * valores nulos, y su invariante es sencilla: "siempre hay un número > 0".
 *
 * CAPA: Dominio (Recipes\Domain). Solo PHP nativo.
 */
final readonly class Quantity
{
    /**
     * Fracciones Unicode que aparecen en los documentos de la nutricionista,
     * con su numerador y denominador.
     */
    private const array UNICODE_FRACTIONS = [
        '½' => [1, 2],
        '⅓' => [1, 3],
        '⅔' => [2, 3],
        '¼' => [1, 4],
        '¾' => [3, 4],
        '⅛' => [1, 8],
    ];

    /**
     * Máximo de dígitos de cada número en fromString(). Una cantidad de una
     * receta no necesita más, y así ningún texto puede desbordar un int.
     */
    private const int MAX_DIGITS = 6;

    /**
     * Privado: solo se crea a través de los constructores con nombre, que
     * validan y simplifican antes de llegar aquí.
     */
    private function __construct(
        private int $numerator,
        private int $denominator,
        private Unit $unit,
    ) {}

    /**
     * Cantidad entera: Quantity::of(200, Unit::Gram).
     */
    public static function of(int $amount, Unit $unit): self
    {
        return self::fraction($amount, 1, $unit);
    }

    /**
     * Cantidad fraccionaria: Quantity::fraction(1, 2, Unit::Jar) es ½ bote.
     * También admite fracciones impropias: fraction(3, 2, ...) es 1 ½.
     */
    public static function fraction(int $numerator, int $denominator, Unit $unit): self
    {
        if ($denominator === 0) {
            throw InvalidQuantity::zeroDenominator();
        }

        // Con enteros, el signo del resultado lo da el signo de ambos:
        // -1/2 y 1/-2 son negativos; -1/-2 sería positivo, pero lo rechazamos
        // igualmente porque nadie escribe así una cantidad.
        if ($numerator <= 0 || $denominator < 0) {
            throw InvalidQuantity::notPositive();
        }

        // Simplificar por el máximo común divisor: 2/4 → 1/2, 200/1 → 200/1.
        $gcd = self::greatestCommonDivisor($numerator, $denominator);

        return new self(intdiv($numerator, $gcd), intdiv($denominator, $gcd), $unit);
    }

    /**
     * Lee el número tal como lo escribe una persona en una receta.
     *
     * Formatos admitidos:
     *   "200"            entero
     *   "1,5" / "1.5"    decimal (con coma, como en español, o con punto)
     *   "1/2"            fracción
     *   "½" / "1 ½" / "1½"  fracción Unicode, sola o con parte entera
     *   "1 1/2"          número mixto
     *
     * Los decimales también se guardan como fracción exacta: "1,5" es 3/2 y
     * "0,25" es 1/4.
     */
    public static function fromString(string $amount, Unit $unit): self
    {
        $text = trim($amount);
        $digits = '(\d{1,'.self::MAX_DIGITS.'})';
        $unicode = '(['.implode('', array_keys(self::UNICODE_FRACTIONS)).'])';

        // CONCEPTO: preg_match con /u
        // El modificador `u` hace que la expresión regular trabaje en UTF-8:
        // sin él, "½" (2 bytes) no se reconocería como un único carácter.
        if (preg_match("/^{$digits}$/u", $text, $m) === 1) {
            return self::of((int) $m[1], $unit);
        }

        if (preg_match("/^{$digits}[.,]{$digits}$/u", $text, $m) === 1) {
            // 1,25 → 125/100 (y fraction() lo simplifica a 5/4).
            return self::fraction((int) ($m[1].$m[2]), 10 ** strlen($m[2]), $unit);
        }

        if (preg_match("/^{$digits}\/{$digits}$/u", $text, $m) === 1) {
            return self::fraction((int) $m[1], (int) $m[2], $unit);
        }

        if (preg_match("/^(?:{$digits}\s*)?{$unicode}$/u", $text, $m) === 1) {
            [$numerator, $denominator] = self::UNICODE_FRACTIONS[$m[2]];

            return self::mixed((int) $m[1], $numerator, $denominator, $unit);
        }

        if (preg_match("/^{$digits}\s+{$digits}\/{$digits}$/u", $text, $m) === 1) {
            return self::mixed((int) $m[1], (int) $m[2], (int) $m[3], $unit);
        }

        throw InvalidQuantity::unreadable($amount);
    }

    public function numerator(): int
    {
        return $this->numerator;
    }

    public function denominator(): int
    {
        return $this->denominator;
    }

    public function unit(): Unit
    {
        return $this->unit;
    }

    /**
     * Igualdad por valor: mismo número y misma unidad.
     *
     * Como la fracción siempre está simplificada, basta comparar campo a
     * campo: fraction(2, 4) y fraction(1, 2) guardan los dos 1/2.
     *
     * Ojo: 1000 gramos NO es igual a "1 kilo" aquí, porque no convertimos
     * entre unidades (no hay una regla de negocio que lo pida todavía).
     */
    public function equals(self $other): bool
    {
        return $this->numerator === $other->numerator
            && $this->denominator === $other->denominator
            && $this->unit === $other->unit;
    }

    /**
     * Número mixto: 1 ½ = (1·2 + 1) / 2 = 3/2.
     */
    private static function mixed(int $whole, int $numerator, int $denominator, Unit $unit): self
    {
        if ($denominator === 0) {
            throw InvalidQuantity::zeroDenominator();
        }

        return self::fraction($whole * $denominator + $numerator, $denominator, $unit);
    }

    /**
     * Algoritmo de Euclides. PHP no trae un gcd() nativo para int (solo
     * gmp_gcd, que exige la extensión GMP), y son tres líneas.
     */
    private static function greatestCommonDivisor(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return $a;
    }
}
