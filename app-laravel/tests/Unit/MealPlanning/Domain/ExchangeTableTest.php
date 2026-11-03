<?php

/*
| Tests de la tabla de intercambios: Exchange (categoría + alimento + ración)
| y ExchangeTable, que los agrupa por categoría y guarda las reglas del plan
| en texto y en orden, sin ejecutarlas (eso es del nivel 8).
*/

use MealPlanning\Domain\Exchange;
use MealPlanning\Domain\ExchangeCategory;
use MealPlanning\Domain\ExchangeTable;
use MealPlanning\Domain\InvalidExchangeTable;

/**
 * Una tabla de ejemplo con los intercambios mezclados, como podrían llegar
 * de un documento leído de arriba abajo.
 */
function sampleExchangeTable(): ExchangeTable
{
    return ExchangeTable::of(
        [
            Exchange::of(ExchangeCategory::Carbohydrates, 'Arroz', '60 g en crudo'),
            Exchange::of(ExchangeCategory::Protein, 'Pechuga de pollo', '100 g'),
            Exchange::of(ExchangeCategory::Carbohydrates, 'Pan integral', '40 g'),
            Exchange::of(ExchangeCategory::Protein, 'Legumbre', '½ bote'),
            Exchange::of(ExchangeCategory::Carbohydrates, 'Legumbre', '½ bote'),
            Exchange::of(ExchangeCategory::Fat, 'Aceite de oliva', '1 cucharada sopera'),
        ],
        [
            'La legumbre cuenta como ½ intercambio de proteína.',
            'La grasa solo en la comida o en la cena, no en ambas.',
        ],
    );
}

/**
 * @param  list<Exchange>  $exchanges
 * @return list<string>
 */
function foods(array $exchanges): array
{
    return array_map(fn (Exchange $exchange) => $exchange->food(), $exchanges);
}

it('groups the exchanges by category, keeping the order of the document', function () {
    $table = sampleExchangeTable();

    expect(foods($table->exchangesIn(ExchangeCategory::Carbohydrates)))->toBe(['Arroz', 'Pan integral', 'Legumbre'])
        ->and(foods($table->exchangesIn(ExchangeCategory::Protein)))->toBe(['Pechuga de pollo', 'Legumbre'])
        ->and(foods($table->exchangesIn(ExchangeCategory::Fat)))->toBe(['Aceite de oliva']);
});

it('returns no exchanges for a category the plan does not list', function () {
    expect(sampleExchangeTable()->exchangesIn(ExchangeCategory::Vegetables))->toBe([]);
});

it('keeps the food and the serving of each exchange', function () {
    $rice = sampleExchangeTable()->exchangesIn(ExchangeCategory::Carbohydrates)[0];

    expect($rice->category())->toBe(ExchangeCategory::Carbohydrates)
        ->and($rice->food())->toBe('Arroz')
        ->and($rice->serving())->toBe('60 g en crudo');
});

it('keeps the text and the order of the rules, without enforcing them', function () {
    // Las reglas se guardan; hacerlas cumplir contra un menú es del nivel 8.
    expect(sampleExchangeTable()->rules())->toBe([
        'La legumbre cuenta como ½ intercambio de proteína.',
        'La grasa solo en la comida o en la cena, no en ambas.',
    ]);
});

it('rejects an empty rule', function () {
    ExchangeTable::of([], ['La legumbre cuenta como ½ intercambio de proteína.', '  ']);
})->throws(InvalidExchangeTable::class, 'Una regla de los intercambios no puede estar vacía.');

it('rejects an exchange without food', function () {
    Exchange::of(ExchangeCategory::Vegetables, '  ', '200 g');
})->throws(InvalidExchangeTable::class, 'Un intercambio de vegetales debe indicar el alimento.');

it('rejects an exchange without serving', function () {
    // Una equivalencia sin cantidad no dice nada: ¿cuánto arroz?
    Exchange::of(ExchangeCategory::Carbohydrates, 'Arroz', '');
})->throws(InvalidExchangeTable::class, 'El intercambio "Arroz" debe indicar la ración.');

it('cannot be changed from outside', function () {
    $table = sampleExchangeTable();

    $rules = $table->rules();
    $rules[] = 'Regla inventada';
    $carbs = $table->exchangesIn(ExchangeCategory::Carbohydrates);
    array_pop($carbs);

    expect($table->rules())->toHaveCount(2)
        ->and($table->exchangesIn(ExchangeCategory::Carbohydrates))->toHaveCount(3);
});

it('is compared by value', function () {
    expect(sampleExchangeTable()->equals(sampleExchangeTable()))->toBeTrue()
        ->and(sampleExchangeTable()->equals(ExchangeTable::of([], sampleExchangeTable()->rules())))->toBeFalse()
        ->and(ExchangeTable::of([], ['Regla A', 'Regla B'])->equals(ExchangeTable::of([], ['Regla B', 'Regla A'])))->toBeFalse()
        ->and(Exchange::of(ExchangeCategory::Protein, 'Huevos', '2 unidades')->equals(Exchange::of(ExchangeCategory::Protein, 'huevos', '2 unidades')))->toBeTrue()
        ->and(Exchange::of(ExchangeCategory::Protein, 'Legumbre', '½ bote')->equals(Exchange::of(ExchangeCategory::Carbohydrates, 'Legumbre', '½ bote')))->toBeFalse();
});
