<?php

declare(strict_types=1);

namespace MealPlanning\Application;

use DateTimeImmutable;
use MealPlanning\Domain\DuplicatedPlanNumber;
use MealPlanning\Domain\DuplicatedPlanStart;
use MealPlanning\Domain\InvalidPlanImport;
use MealPlanning\Domain\NutritionPlanId;
use MealPlanning\Domain\PlanImportId;
use MealPlanning\Domain\PlanImportRepository;
use Shared\Domain\DomainException;

/**
 * ConfirmPlanImport: caso de uso «confirmar una importación con la fecha de
 * inicio»: la convierte en un NutritionPlan.
 *
 *     PlanImport ──selfWeekForConfirmation()──► ¿se puede? (si no, InvalidPlanImport)
 *        │
 *        ▼  copiar el contenido de los dos Excel + la fecha de inicio
 *     RegisterNutritionPlanCommand
 *        │
 *        ▼
 *     RegisterNutritionPlan   ← TODAS las reglas del plan (lunes, dos semanas,
 *        │                      número e inicio únicos, recetas…)
 *        ▼
 *     PlanImport::confirm(planId) ──► PlanImportRepository::save()
 *
 * CONCEPTO: reutilizar un caso de uso a través de su comando
 * Confirmar no vuelve a escribir cómo se construye un plan: compone el MISMO
 * comando que la API del alta manual (POST /api/nutrition-plans, ADR 0014) y
 * llama al MISMO caso de uso. Por eso el plan que sale es igual al del alta
 * manual con los mismos datos, y una regla nueva del plan (nivel 8) se
 * aplicará también a las importaciones sin tocar este código. El contenido se
 * copia sin traducir porque la importación lo guarda con la forma del
 * contrato del alta (ADR 0025).
 *
 * RegisterNutritionPlan entra por el constructor, como un puerto: el
 * contenedor de Laravel lo construye con sus propias dependencias, y los
 * tests, a mano con los adaptadores en memoria.
 *
 * CONCEPTO: dos agregados, dos guardados (consistencia entre agregados)
 * Confirmar cambia dos agregados: crea un NutritionPlan y marca la
 * PlanImport. La regla práctica de DDD es «un agregado por transacción»:
 * cada uno se guarda en su repositorio, el plan primero (sin plan no hay id
 * que anotar). Si el segundo guardado fallara, el plan ya existiría y la
 * importación seguiría pendiente; confirmarla otra vez daría
 * DuplicatedPlanNumber (el número del plan es único, regla 22), así que nunca
 * habría dos planes iguales. Para una aplicación de un solo usuario y una
 * fila por agregado, es un riesgo aceptado y documentado (ADR 0025), en vez
 * de un puerto de transacciones en el núcleo.
 *
 * CONCEPTO: todo o nada (desde el punto de vista del plan)
 * Si la importación no se puede confirmar, o el plan incumple una regla
 * (empieza en martes, su número ya existe…), la excepción sale ANTES de
 * guardar nada: ni plan ni importación confirmada.
 *
 * CAPA: Aplicación (MealPlanning\Application).
 */
final readonly class ConfirmPlanImport
{
    public function __construct(
        private PlanImportRepository $imports,
        private RegisterNutritionPlan $registerPlan,
    ) {}

    /**
     * @throws PlanImportNotFound si no existe la importación
     * @throws InvalidPlanImport si ya está confirmada o le falta la semana 2
     * @throws DuplicatedPlanStart si otro plan ya empieza ese día
     * @throws DuplicatedPlanNumber si otro plan ya tiene ese número
     * @throws DomainException si el plan incumple otra regla del negocio
     */
    public function __invoke(string $importId, DateTimeImmutable $startsOn): NutritionPlanId
    {
        $id = PlanImportId::fromString($importId);
        $import = $this->imports->find($id) ?? throw PlanImportNotFound::withId($id);

        // Primero, la regla de la importación: sin semana 2 (o ya
        // confirmada) no se llega a intentar crear el plan.
        $selfWeek = $import->selfWeekForConfirmation();
        $nutritionistPlan = $import->nutritionistPlan();

        $planId = ($this->registerPlan)(new RegisterNutritionPlanCommand(
            number: $nutritionistPlan->number()->value(),
            startsOn: $startsOn,
            fixedMeals: $nutritionistPlan->fixedMeals(),
            exchanges: $nutritionistPlan->exchanges(),
            exchangeRules: $nutritionistPlan->exchangeRules(),
            nutritionistWeek: $nutritionistPlan->nutritionistWeek(),
            selfWeek: $selfWeek->days(),
        ));

        $this->imports->save($import->confirm($planId));

        return $planId;
    }
}
