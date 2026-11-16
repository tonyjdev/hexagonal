<?php

/*
| Tests de los casos de uso de la importación de un plan (HEXM-0032):
| StartPlanImport, ReplaceImportedNutritionistPlan, AttachSelfWeekToPlanImport
| y ConfirmPlanImport, y de su consulta, GetPlanImport (HEXM-0033).
|
| Sin Laravel, sin PostgreSQL y sin el servicio Python: el lector es el fake
| InMemoryPlanReader, que devuelve las lecturas del contrato versionado (las
| respuestas reales del servicio con los Excel ficticios del plan 99), y los
| repositorios son los de memoria. Los dos cumplen los mismos tests de
| contrato que sus adaptadores reales, así que lo que se demuestra aquí vale
| también con el servicio y con la base de datos.
*/

use MealPlanning\Application\AttachSelfWeekToPlanImport;
use MealPlanning\Application\ConfirmPlanImport;
use MealPlanning\Application\GetPlanImport;
use MealPlanning\Application\PlanDocument;
use MealPlanning\Application\PlanImportNotFound;
use MealPlanning\Application\PlanReader;
use MealPlanning\Application\PlanReaderUnavailable;
use MealPlanning\Application\RegisterNutritionPlan;
use MealPlanning\Application\ReplaceImportedNutritionistPlan;
use MealPlanning\Application\StartPlanImport;
use MealPlanning\Application\UnreadableDocument;
use MealPlanning\Domain\DuplicatedPlanNumber;
use MealPlanning\Domain\InvalidNutritionPlan;
use MealPlanning\Domain\InvalidPlanImport;
use MealPlanning\Domain\InvalidPlanImportId;
use MealPlanning\Domain\NutritionPlan;
use MealPlanning\Domain\PlanImport;
use MealPlanning\Domain\PlanImportId;
use MealPlanning\Domain\PlanImportStatus;
use MealPlanning\Infrastructure\InMemoryNutritionPlanRepository;
use MealPlanning\Infrastructure\InMemoryPlanImportRepository;
use MealPlanning\Infrastructure\InMemoryPlanReader;
use MealPlanning\Infrastructure\InMemoryRecipeCatalog;
use MealPlanning\Infrastructure\Persistence\Eloquent\NutritionPlanMapper;
use Tests\Support\MealPlanning\ImporterContract;
use Tests\Support\MealPlanning\NutritionPlanMother;
use Tests\Support\MealPlanning\PlanImportMother;

/**
 * Los casos de uso de la importación montados a mano con los adaptadores en
 * memoria (lo que haría el contenedor de Laravel con los reales).
 */
final class PlanImportScenario
{
    public readonly InMemoryPlanImportRepository $imports;

    public readonly InMemoryNutritionPlanRepository $plans;

    public function __construct(private PlanReader $reader)
    {
        $this->imports = new InMemoryPlanImportRepository;
        $this->plans = new InMemoryNutritionPlanRepository;
    }

    /**
     * Un escenario cuyo lector lee los dos Excel del contrato.
     */
    public static function reading(): self
    {
        return new self(InMemoryPlanReader::returning(ImporterContract::nutritionistPlanReading(), ImporterContract::selfWeekReading()));
    }

    public function start(): StartPlanImport
    {
        return new StartPlanImport($this->reader, $this->imports);
    }

    public function replaceNutritionistPlan(): ReplaceImportedNutritionistPlan
    {
        return new ReplaceImportedNutritionistPlan($this->reader, $this->imports);
    }

    public function attachSelfWeek(): AttachSelfWeekToPlanImport
    {
        return new AttachSelfWeekToPlanImport($this->reader, $this->imports);
    }

    public function confirm(): ConfirmPlanImport
    {
        return new ConfirmPlanImport($this->imports, new RegisterNutritionPlan($this->plans, new InMemoryRecipeCatalog));
    }

    public function get(): GetPlanImport
    {
        return new GetPlanImport($this->imports);
    }

    /**
     * Empieza una importación y le añade la semana 2: lista para confirmar.
     */
    public function readyImport(): string
    {
        $id = ($this->start())(nutritionistDocument())->value();
        ($this->attachSelfWeek())($id, selfWeekDocument());

        return $id;
    }

    public function import(string $id): PlanImport
    {
        return $this->imports->find(PlanImportId::fromString($id)) ?? throw new LogicException('La importación no se ha guardado.');
    }
}

function nutritionistDocument(): PlanDocument
{
    return new PlanDocument('plan-99.xlsx', 'contenido del Excel de la nutricionista');
}

function selfWeekDocument(): PlanDocument
{
    return new PlanDocument('semana-2.xlsx', 'contenido del Excel de la semana 2');
}

/**
 * Un plan como fila de la base de datos SIN su id: todo su contenido, para
 * comparar dos planes que se han creado por caminos distintos (cada uno con
 * su id). El mapper es puro PHP: no necesita PostgreSQL.
 *
 * @return array<string, mixed>
 */
function planContent(NutritionPlan $plan): array
{
    $row = (new NutritionPlanMapper)->toRow($plan);
    unset($row['id']);

    return $row;
}

// --- Empezar --------------------------------------------------------------------

it('starts an import by reading the nutritionist excel', function () {
    $scenario = PlanImportScenario::reading();

    $id = ($scenario->start())(nutritionistDocument());

    $import = $scenario->import($id->value());
    expect($import->status())->toBe(PlanImportStatus::AwaitingSelfWeek)
        ->and($import->number()->value())->toBe(ImporterContract::nutritionistPlanReading()->number)
        ->and($import->nutritionistPlan()->nutritionistWeek())->toBe(ImporterContract::nutritionistPlanReading()->nutritionistWeek);
});

it('creates no import when the excel cannot be read', function (PlanReader $reader, string $exception) {
    $scenario = new PlanImportScenario($reader);

    expect(fn () => ($scenario->start())(nutritionistDocument()))->toThrow($exception);
})->with([
    'unreadable document (422)' => [fn () => InMemoryPlanReader::rejecting('Hoja «Menús», celda A1: falta la cabecera.'), UnreadableDocument::class],
    'reader unavailable (503)' => [fn () => InMemoryPlanReader::unavailable(), PlanReaderUnavailable::class],
]);

// --- La semana 2 ----------------------------------------------------------------

it('attaches the self week, and the import is ready to confirm', function () {
    $scenario = PlanImportScenario::reading();
    $id = ($scenario->start())(nutritionistDocument())->value();

    ($scenario->attachSelfWeek())($id, selfWeekDocument());

    expect($scenario->import($id)->status())->toBe(PlanImportStatus::ReadyToConfirm)
        ->and($scenario->import($id)->selfWeek()?->days())->toBe(ImporterContract::selfWeekReading()->selfWeek);
});

it('replaces the nutritionist excel of a pending import, keeping its self week', function () {
    $scenario = PlanImportScenario::reading();
    $id = $scenario->readyImport();

    ($scenario->replaceNutritionistPlan())($id, nutritionistDocument());

    expect($scenario->import($id)->status())->toBe(PlanImportStatus::ReadyToConfirm);
});

it('says when the import does not exist, before reading anything', function (Closure $action) {
    // Un lector caído: si el caso de uso leyera antes de buscar, saldría
    // PlanReaderUnavailable en lugar de PlanImportNotFound.
    $scenario = new PlanImportScenario(InMemoryPlanReader::unavailable());

    $action($scenario, PlanImportId::generate()->value());
})->with([
    'attaching the self week' => fn (PlanImportScenario $s, string $id) => ($s->attachSelfWeek())($id, selfWeekDocument()),
    'replacing the nutritionist excel' => fn (PlanImportScenario $s, string $id) => ($s->replaceNutritionistPlan())($id, nutritionistDocument()),
    'confirming' => fn (PlanImportScenario $s, string $id) => ($s->confirm())($id, new DateTimeImmutable('2026-11-02')),
])->throws(PlanImportNotFound::class, 'No existe ninguna importación de plan con el identificador');

it('rejects a malformed import id as a domain error', function () {
    (PlanImportScenario::reading()->confirm())('no-es-un-uuid', new DateTimeImmutable('2026-11-02'));
})->throws(InvalidPlanImportId::class);

// --- Confirmar ------------------------------------------------------------------

it('confirms an import into the same plan the manual registration would give', function () {
    $scenario = PlanImportScenario::reading();
    $id = $scenario->readyImport();

    $planId = ($scenario->confirm())($id, new DateTimeImmutable('2026-11-02'));

    // El mismo contenido por el otro camino: el alta manual con los mismos
    // datos, en otro repositorio (si no, chocarían el número y el inicio).
    $manualPlans = new InMemoryNutritionPlanRepository;
    $manualId = (new RegisterNutritionPlan($manualPlans, new InMemoryRecipeCatalog))(PlanImportMother::manualRegisterCommand('2026-11-02'));

    $imported = $scenario->plans->find($planId) ?? throw new LogicException('No se ha creado el plan.');
    $manual = $manualPlans->find($manualId) ?? throw new LogicException;

    expect(planContent($imported))->toBe(planContent($manual))
        ->and($imported->number()->value())->toBe(99);
});

it('leaves the import confirmed with the id of the new plan', function () {
    $scenario = PlanImportScenario::reading();
    $id = $scenario->readyImport();

    $planId = ($scenario->confirm())($id, new DateTimeImmutable('2026-11-02'));

    expect($scenario->import($id)->status())->toBe(PlanImportStatus::Confirmed)
        ->and($scenario->import($id)->plan()?->equals($planId))->toBeTrue();
});

it('rejects confirming without the self week, and creates no plan', function () {
    $scenario = PlanImportScenario::reading();
    $id = ($scenario->start())(nutritionistDocument())->value();

    expect(fn () => ($scenario->confirm())($id, new DateTimeImmutable('2026-11-02')))
        ->toThrow(InvalidPlanImport::class, 'La importación del plan 99 no se puede confirmar: falta el Excel de la semana 2.')
        ->and($scenario->plans->history())->toBe([])
        ->and($scenario->import($id)->status())->toBe(PlanImportStatus::AwaitingSelfWeek);
});

it('rejects a start date that is not a Monday, and creates no plan', function () {
    $scenario = PlanImportScenario::reading();
    $id = $scenario->readyImport();

    // 2026-11-03 es martes: la regla es del plan (RegisterNutritionPlan), y
    // la importación la hereda sin escribirla otra vez.
    expect(fn () => ($scenario->confirm())($id, new DateTimeImmutable('2026-11-03')))
        ->toThrow(InvalidNutritionPlan::class, 'Un plan empieza siempre en lunes, y el 03/11/2026 es martes.')
        ->and($scenario->plans->history())->toBe([])
        ->and($scenario->import($id)->status())->toBe(PlanImportStatus::ReadyToConfirm);
});

it('applies the rules between plans when confirming: the plan number is unique', function () {
    $scenario = PlanImportScenario::reading();
    $scenario->plans->save(NutritionPlanMother::plan(number: 99, startsOn: '2026-09-07'));
    $id = $scenario->readyImport();

    expect(fn () => ($scenario->confirm())($id, new DateTimeImmutable('2026-11-02')))
        ->toThrow(DuplicatedPlanNumber::class)
        ->and($scenario->plans->history())->toHaveCount(1)
        ->and($scenario->import($id)->status())->toBe(PlanImportStatus::ReadyToConfirm);
});

it('cannot confirm the same import twice, nor change it afterwards', function (Closure $action) {
    $scenario = PlanImportScenario::reading();
    $id = $scenario->readyImport();
    ($scenario->confirm())($id, new DateTimeImmutable('2026-11-02'));

    expect(fn () => $action($scenario, $id))
        ->toThrow(InvalidPlanImport::class, 'La importación del plan 99 ya está confirmada')
        ->and($scenario->plans->history())->toHaveCount(1);
})->with([
    'confirming again' => fn (PlanImportScenario $s, string $id) => ($s->confirm())($id, new DateTimeImmutable('2026-11-09')),
    'attaching another self week' => fn (PlanImportScenario $s, string $id) => ($s->attachSelfWeek())($id, selfWeekDocument()),
    'replacing the nutritionist excel' => fn (PlanImportScenario $s, string $id) => ($s->replaceNutritionistPlan())($id, nutritionistDocument()),
]);

it('allows several pending imports at the same time', function () {
    $scenario = PlanImportScenario::reading();

    $first = ($scenario->start())(nutritionistDocument());
    $second = ($scenario->start())(nutritionistDocument());

    expect($first->equals($second))->toBeFalse()
        ->and($scenario->import($first->value())->status())->toBe(PlanImportStatus::AwaitingSelfWeek)
        ->and($scenario->import($second->value())->status())->toBe(PlanImportStatus::AwaitingSelfWeek);
});

// --- Consultar (HEXM-0033) ------------------------------------------------------

it('tells what an import is missing at each step', function () {
    $scenario = PlanImportScenario::reading();
    $id = ($scenario->start())(nutritionistDocument())->value();

    // Recién empezada: falta la semana 2.
    $awaiting = ($scenario->get())($id);
    expect($awaiting->id)->toBe($id)
        ->and($awaiting->number)->toBe(99)
        ->and($awaiting->status)->toBe('awaiting_self_week')
        ->and($awaiting->statusLabel)->toBe('pendiente de la semana 2')
        ->and($awaiting->hasSelfWeek)->toBeFalse()
        ->and($awaiting->planId)->toBeNull();

    // Con la semana 2: solo falta confirmarla con la fecha de inicio.
    ($scenario->attachSelfWeek())($id, selfWeekDocument());
    $ready = ($scenario->get())($id);
    expect($ready->status)->toBe('ready_to_confirm')
        ->and($ready->statusLabel)->toBe('lista para confirmar')
        ->and($ready->hasSelfWeek)->toBeTrue()
        ->and($ready->planId)->toBeNull();

    // Confirmada: ya no le falta nada y dice qué plan se creó.
    $planId = ($scenario->confirm())($id, new DateTimeImmutable('2026-11-02'));
    $confirmed = ($scenario->get())($id);
    expect($confirmed->status)->toBe('confirmed')
        ->and($confirmed->statusLabel)->toBe('confirmada')
        ->and($confirmed->planId)->toBe($planId->value());
});

it('says when the queried import does not exist', function () {
    (PlanImportScenario::reading()->get())(PlanImportId::generate()->value());
})->throws(PlanImportNotFound::class, 'No existe ninguna importación de plan con el identificador');

it('rejects a malformed id when querying an import', function () {
    (PlanImportScenario::reading()->get())('no-es-un-uuid');
})->throws(InvalidPlanImportId::class);
