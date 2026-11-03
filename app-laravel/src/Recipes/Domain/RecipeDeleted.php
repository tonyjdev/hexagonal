<?php

declare(strict_types=1);

namespace Recipes\Domain;

use DateTimeImmutable;
use Shared\Domain\DomainEvent;

/**
 * RecipeDeleted: se ha borrado una receta del recetario.
 *
 *     RecipeDeleted { recipeId: "0190a…", occurredOn: 2026-11-03 }
 *
 * CONCEPTO: evento de dominio (ver Shared\Domain\DomainEvent)
 * Nombre en pasado y del negocio, inmutable (readonly) y con lo justo: qué
 * receta y qué día. Lo crea la propia receta al borrarse (Recipe::delete())
 * y lo publica el caso de uso DeleteRecipe cuando el borrado se ha
 * confirmado. Los planes que apuntan a la receta reaccionarán a él
 * (HEXM-0027); el recetario no sabe que existen.
 *
 * DECISIÓN: datos primitivos (el id como texto), no el value object RecipeId
 * (HEXM-0026, ADR 0018)
 * Un evento es un MENSAJE hacia fuera del agregado, y a menudo del contexto.
 * Si llevara un RecipeId, quien lo escucha tendría que conocer el modelo del
 * recetario para leerlo; con un texto, basta con el evento. Además, un
 * mensaje de datos simples se puede serializar tal cual el día que vaya por
 * una cola o se guarde en una tabla (patrón outbox, fuera de alcance). Quien
 * lo construye sí recibe un RecipeId: así no se puede crear con un id
 * inválido.
 *
 * La fecha es un día del calendario (Clock::today(), en la zona del usuario),
 * no un instante: al negocio le basta "se borró el 03/11" (ADR 0018).
 *
 * CAPA: Dominio (Recipes\Domain).
 */
final readonly class RecipeDeleted implements DomainEvent
{
    private function __construct(
        private string $recipeId,
        private DateTimeImmutable $occurredOn,
    ) {}

    /**
     * Lo crea la receta al borrarse (Recipe::delete()), con su propio id:
     * así el evento siempre habla de la receta que se borra.
     */
    public static function of(RecipeId $recipe, DateTimeImmutable $on): self
    {
        return new self($recipe->value(), $on);
    }

    /**
     * El id de la receta borrada, como texto (un UUID en minúsculas).
     */
    public function recipeId(): string
    {
        return $this->recipeId;
    }

    public function occurredOn(): DateTimeImmutable
    {
        return $this->occurredOn;
    }
}
