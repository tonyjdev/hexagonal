<?php

/*
| Tests de Quantity: el value object más delicado del nivel 1, porque tiene
| que representar fracciones (½, ⅓, ¾) de forma EXACTA.
|
| Son tests unitarios de dominio: no arrancan Laravel ni tocan la base de
| datos. Si alguno lo necesitara, sería señal de que Quantity depende de algo
| que no debe.
*/

use Recipes\Domain\InvalidQuantity;
use Recipes\Domain\Quantity;
use Recipes\Domain\Unit;
use Shared\Domain\DomainException;

describe('exact fractions', function () {
    it('represents ½, ⅓ and ¾ exactly as numerator and denominator', function (string $text, int $numerator, int $denominator) {
        $quantity = Quantity::fromString($text, Unit::Jar);

        expect($quantity->numerator())->toBe($numerator)
            ->and($quantity->denominator())->toBe($denominator);
    })->with([
        '½' => ['½', 1, 2],
        '⅓' => ['⅓', 1, 3],
        '¾' => ['¾', 3, 4],
    ]);

    it('keeps ⅓ exact where a float cannot', function () {
        // Con float, un tercio se corta en algún decimal: 0,333 no es ⅓.
        // Con numerador/denominador, ⅓ y 2/6 son exactamente lo mismo.
        $third = Quantity::fromString('⅓', Unit::Glass);

        expect($third->equals(Quantity::fraction(2, 6, Unit::Glass)))->toBeTrue()
            ->and($third->equals(Quantity::fromString('0,333', Unit::Glass)))->toBeFalse()
            // Y el motivo de no usar float, demostrado en PHP:
            ->and(0.1 + 0.2 === 0.3)->toBeFalse();
    });

    it('stores fractions simplified', function () {
        $quantity = Quantity::fraction(4, 8, Unit::Can);

        expect($quantity->numerator())->toBe(1)
            ->and($quantity->denominator())->toBe(2);
    });
});

describe('reading amounts written by people', function () {
    it('reads the usual formats', function (string $text, int $numerator, int $denominator) {
        $quantity = Quantity::fromString($text, Unit::Gram);

        expect([$quantity->numerator(), $quantity->denominator()])->toBe([$numerator, $denominator]);
    })->with([
        'entero' => ['200', 200, 1],
        'decimal con coma' => ['1,5', 3, 2],
        'decimal con punto' => ['0.25', 1, 4],
        'fracción' => ['1/2', 1, 2],
        'fracción impropia' => ['3/2', 3, 2],
        'unicode con parte entera' => ['1 ½', 3, 2],
        'unicode pegado al entero' => ['2¾', 11, 4],
        'número mixto' => ['1 1/2', 3, 2],
        'con espacios alrededor' => ['  150 ', 150, 1],
    ]);

    it('rejects text that is not an amount', function (string $text) {
        Quantity::fromString($text, Unit::Gram);
    })->with(['', 'al gusto', 'una pizca', '1/2/3', 'abc', '1,', '½½', '1234567'])
        ->throws(InvalidQuantity::class);
});

describe('always valid', function () {
    it('cannot be zero or negative', function (Closure $create) {
        // Cada caso del dataset es una closure que intenta crear una
        // cantidad inválida. Si el parámetro acepta una Closure (Closure o
        // callable), Pest la pasa tal cual sin ejecutarla, y la ejecutamos
        // aquí dentro para que ->throws() capture la excepción. Basta con una
        // closure por caso: con fn () => fn () => ..., el test solo recibiría
        // la closure interior y no se lanzaría nada.
        $create();
    })->with([
        'cero' => fn () => Quantity::of(0, Unit::Gram),
        'negativa' => fn () => Quantity::of(-5, Unit::Gram),
        'fracción negativa' => fn () => Quantity::fraction(1, -2, Unit::Gram),
        'cero escrito' => fn () => Quantity::fromString('0', Unit::Gram),
        'cero en fracción' => fn () => Quantity::fromString('0/3', Unit::Gram),
    ])->throws(InvalidQuantity::class, 'La cantidad debe ser mayor que cero.');

    it('cannot have a zero denominator', function () {
        Quantity::fromString('1/0', Unit::Gram);
    })->throws(InvalidQuantity::class, 'Una fracción no puede tener cero como denominador.');

    it('raises a domain exception with a message in Spanish', function () {
        // Cualquier error de Quantity es un error de dominio: un futuro
        // manejador de Laravel podrá capturar la clase base y devolver un 422.
        expect(fn () => Quantity::fromString('al gusto', Unit::Gram))
            ->toThrow(DomainException::class, 'No se entiende la cantidad "al gusto"');
    });
});

describe('comparison by value', function () {
    it('is equal to another quantity with the same amount and unit', function () {
        expect(Quantity::fromString('1 ½', Unit::Glass)->equals(Quantity::fraction(3, 2, Unit::Glass)))->toBeTrue();
    });

    it('is not equal if the unit is different', function () {
        expect(Quantity::of(1, Unit::Tablespoon)->equals(Quantity::of(1, Unit::Teaspoon)))->toBeFalse();
    });

    it('is not equal if the amount is different', function () {
        expect(Quantity::of(100, Unit::Gram)->equals(Quantity::of(200, Unit::Gram)))->toBeFalse();
    });
});
