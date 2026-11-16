<?php

declare(strict_types=1);

namespace MealPlanning\Infrastructure;

use LogicException;
use MealPlanning\Application\NutritionistPlanReading;
use MealPlanning\Application\PlanDocument;
use MealPlanning\Application\PlanReader;
use MealPlanning\Application\PlanReaderUnavailable;
use MealPlanning\Application\SelfWeekReading;
use MealPlanning\Application\UnreadableDocument;
use RuntimeException;

/**
 * InMemoryPlanReader: un lector de planes de mentira, que devuelve las
 * lecturas (o los errores) que se le dan al construirlo.
 *
 * CONCEPTO: fake de un puerto hacia un servicio externo
 * Con él, los casos de uso de la importación (HEXM-0032, HEXM-0033) se
 * prueban sin el servicio Python, sin red y sin Excel: el test decide qué
 * «contiene» cada documento. Cumple los mismos tests de contrato que
 * HttpPlanReader (PlanReaderContractTest), incluidos los errores, así que lo
 * que demuestren los tests rápidos vale también con el servicio de verdad.
 *
 * Tiene tres formas de construirse, una por comportamiento del puerto:
 *   - returning(): lee y devuelve las lecturas dadas;
 *   - rejecting(): el documento no tiene la forma esperada;
 *   - unavailable(): el lector no está disponible.
 *
 * Como los otros adaptadores en memoria, vive en Infrastructure aunque no
 * use Laravel: es un adaptador más del puerto.
 *
 * CAPA: Infraestructura (MealPlanning\Infrastructure).
 */
final readonly class InMemoryPlanReader implements PlanReader
{
    /**
     * @param  list<string>  $problems
     */
    private function __construct(
        private ?NutritionistPlanReading $nutritionistPlan = null,
        private ?SelfWeekReading $selfWeek = null,
        private array $problems = [],
        private bool $unavailable = false,
    ) {}

    /**
     * Lee siempre estas lecturas. La que no se dé no se puede leer: un test
     * que la pida está mal escrito, y se le dice con una LogicException.
     */
    public static function returning(?NutritionistPlanReading $nutritionistPlan = null, ?SelfWeekReading $selfWeek = null): self
    {
        return new self(nutritionistPlan: $nutritionistPlan, selfWeek: $selfWeek);
    }

    /**
     * Cualquier documento «no tiene la forma esperada», con estos problemas.
     */
    public static function rejecting(string $problem, string ...$moreProblems): self
    {
        return new self(problems: [$problem, ...array_values($moreProblems)]);
    }

    /**
     * El lector «está caído».
     */
    public static function unavailable(): self
    {
        return new self(unavailable: true);
    }

    public function readNutritionistPlan(PlanDocument $document): NutritionistPlanReading
    {
        $this->failIfConfiguredTo($document);

        return $this->nutritionistPlan ?? throw new LogicException('InMemoryPlanReader no tiene lectura del Excel de la nutricionista: pásala a returning().');
    }

    public function readSelfWeek(PlanDocument $document): SelfWeekReading
    {
        $this->failIfConfiguredTo($document);

        return $this->selfWeek ?? throw new LogicException('InMemoryPlanReader no tiene lectura de la semana 2: pásala a returning().');
    }

    private function failIfConfiguredTo(PlanDocument $document): void
    {
        if ($this->unavailable) {
            throw PlanReaderUnavailable::unreachable(new RuntimeException('InMemoryPlanReader simula un lector caído.'));
        }

        if ($this->problems !== []) {
            throw UnreadableDocument::withProblems($document, $this->problems);
        }
    }
}
