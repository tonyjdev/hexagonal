<?php

/*
| Tests del borrado de una receta en el dominio (HEXM-0026).
|
| CONCEPTO: el agregado registra sus eventos
| Borrar una receta no la hace desaparecer de la memoria (eso es cosa del
| repositorio): lo que hace es ANOTAR el evento RecipeDeleted. Estos tests
| comprueban qué se anota, cuántas veces se entrega y que una receta recién
| creada o cargada no trae eventos "de serie".
*/

use Recipes\Domain\RecipeDeleted;
use Recipes\Domain\RecipeId;
use Shared\Domain\DomainEvent;
use Tests\Support\Recipes\RecipeMother;

it('records a RecipeDeleted event with its id and the day it was deleted', function () {
    $id = RecipeId::generate();
    $recipe = RecipeMother::lentilsWithPisto($id);

    $recipe->delete(new DateTimeImmutable('2026-11-03'));
    $events = $recipe->releaseEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(RecipeDeleted::class)
        // Un evento es un evento de dominio, para el bus de cualquier contexto.
        ->and($events[0])->toBeInstanceOf(DomainEvent::class);

    /** @var RecipeDeleted $event */
    $event = $events[0];

    // Datos simples: el id como texto, para que lo entienda quien escuche.
    expect($event->recipeId())->toBe($id->value())
        ->and($event->occurredOn()->format('Y-m-d'))->toBe('2026-11-03');
});

it('releases the events only once', function () {
    // releaseEvents() los entrega y los olvida: no se publican dos veces.
    $recipe = RecipeMother::chickpeaSalad();
    $recipe->delete(new DateTimeImmutable('2026-11-03'));

    expect($recipe->releaseEvents())->toHaveCount(1)
        ->and($recipe->releaseEvents())->toBe([]);
});

it('has no events when it is created or renamed', function () {
    // Solo el borrado es, hoy, un hecho que interese fuera del recetario.
    $recipe = RecipeMother::chickpeaSalad();
    $recipe->rename('Ensalada de garbanzos y atún');

    expect($recipe->releaseEvents())->toBe([]);
});
