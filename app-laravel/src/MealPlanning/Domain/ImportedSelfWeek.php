<?php

declare(strict_types=1);

namespace MealPlanning\Domain;

/**
 * ImportedSelfWeek: lo que una importación ha leído del Excel de la semana 2
 * del usuario: los días 8 a 14 con su comida y su cena (el 13, vacío).
 *
 * Como ImportedNutritionistPlan: la versión del dominio de SelfWeekReading,
 * con la forma de self_week del contrato del alta (ADR 0014), sin convertirla
 * en un MenuWeek. Quien sabe que el día 13 es el sábado es
 * MenuWeek::fromSelfDays(), y lo aplicará al confirmar (ADR 0025).
 *
 * CAPA: Dominio (MealPlanning\Domain).
 */
final readonly class ImportedSelfWeek
{
    /**
     * @param  list<array{day: int, lunch: array{name: string, ingredients: list<array{name: string, quantity: string|null}>}|null, dinner: array{name: string, ingredients: list<array{name: string, quantity: string|null}>}|null}>  $days
     */
    private function __construct(private array $days) {}

    /**
     * @param  list<array{day: int, lunch: array{name: string, ingredients: list<array{name: string, quantity: string|null}>}|null, dinner: array{name: string, ingredients: list<array{name: string, quantity: string|null}>}|null}>  $days
     */
    public static function of(array $days): self
    {
        return new self($days);
    }

    /**
     * @return list<array{day: int, lunch: array{name: string, ingredients: list<array{name: string, quantity: string|null}>}|null, dinner: array{name: string, ingredients: list<array{name: string, quantity: string|null}>}|null}>
     */
    public function days(): array
    {
        return $this->days;
    }
}
