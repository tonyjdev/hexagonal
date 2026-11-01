<?php

declare(strict_types=1);

namespace Recipes\Domain;

/**
 * Unit: unidad de medida de una cantidad (gramos, cucharadas, botes...).
 *
 * CONCEPTO: enum como tipo del dominio
 * Un enum de PHP (desde 8.1) es un tipo con un conjunto CERRADO de valores.
 * Frente a usar un string ('gramo', 'gr', 'g'...), el enum:
 *   - impide valores inventados o mal escritos: no existe Unit::Gramo2;
 *   - hace explícito el vocabulario del negocio en un único sitio;
 *   - deja que PHPStan compruebe los `match` exhaustivos (si añades un caso y
 *     olvidas tratarlo en label(), el análisis estático lo detecta).
 *
 * CONCEPTO: backed enum
 * Cada caso lleva un valor string ('gram', 'tablespoon'...). Es el valor que
 * se guardará en la base de datos y viajará por la API (nivel 2), por eso va
 * en inglés como el resto de contratos. Unit::from('gram') lo recupera.
 * El texto en español para el usuario lo da label().
 *
 * DECISIÓN: no hay unidad para "al gusto" ni "una pizca". Una cantidad sin
 * número no es una Quantity: es la AUSENCIA de cantidad, y se modelará con un
 * Ingredient cuya cantidad es opcional (HEXM-0006). Ver Quantity.
 *
 * La lista sale de los planes reales de la nutricionista (ver el glosario de
 * docs/DOMINIO.md) y crecerá cuando aparezcan unidades nuevas.
 *
 * CAPA: Dominio (Recipes\Domain).
 */
enum Unit: string
{
    case Gram = 'gram';
    case Millilitre = 'millilitre';
    case Piece = 'piece';
    case Tablespoon = 'tablespoon';
    case Teaspoon = 'teaspoon';
    case Glass = 'glass';
    case Slice = 'slice';
    case Fillet = 'fillet';
    case Can = 'can';
    case Jar = 'jar';

    /**
     * Nombre de la unidad en español, para mostrarlo al usuario.
     *
     * `match` sin rama `default`: si se añade un caso nuevo al enum y no se
     * le da etiqueta aquí, PHP lanza UnhandledMatchError y PHPStan lo avisa
     * antes de ejecutar nada.
     */
    public function label(): string
    {
        return match ($this) {
            self::Gram => 'gramo',
            self::Millilitre => 'mililitro',
            self::Piece => 'unidad',
            self::Tablespoon => 'cucharada sopera',
            self::Teaspoon => 'cucharadita',
            self::Glass => 'vaso',
            self::Slice => 'loncha',
            self::Fillet => 'filete',
            self::Can => 'lata',
            self::Jar => 'bote',
        };
    }
}
