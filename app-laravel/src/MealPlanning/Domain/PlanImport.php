<?php

declare(strict_types=1);

namespace MealPlanning\Domain;

/**
 * PlanImport: la importación de un plan nutricional, mientras se juntan sus
 * dos documentos («importación pendiente»).
 *
 *     PlanImport (raíz)                ← PlanImportId
 *     ├── ImportedNutritionistPlan     ← el Excel de la nutricionista (siempre)
 *     ├── ImportedSelfWeek | null      ← el Excel de la semana 2 (cuando llega)
 *     └── NutritionPlanId | null       ← el plan creado al confirmarla
 *
 * CONCEPTO: modelar un proceso como un agregado propio (ADR 0025)
 * Un plan llega en dos documentos y en dos momentos: la nutricionista entrega
 * el suyo y, días después, el usuario elabora la semana 2. Entre medias, lo
 * leído tiene que vivir en algún sitio. Había dos caminos:
 *
 *   1. Relajar NutritionPlan para que admita «solo la semana 1». Rompería su
 *      invariante principal (regla 1: exactamente dos semanas) y obligaría a
 *      todo el código que usa un plan (el calendario, el histórico…) a
 *      preguntarse si está completo.
 *   2. Un agregado NUEVO para el proceso, con sus propios estados, que solo
 *      al final se convierte en un NutritionPlan. Es el elegido: el plan
 *      sigue siendo siempre válido, y lo incompleto tiene nombre propio.
 *
 * CONCEPTO: ciclo de vida con transiciones con nombre (PlanImportStatus)
 *   - start(): empieza con el Excel de la nutricionista;
 *   - replaceNutritionistPlan() y attachSelfWeek(): cambian un documento
 *     (el segundo también AÑADE la semana 2 si aún no estaba);
 *   - confirm(): ya creado el plan, la importación queda confirmada con su
 *     id y no admite más cambios.
 * Una transición que no se permite se rechaza con InvalidPlanImport, en
 * español: es una regla del negocio, no un fallo técnico.
 *
 * CONCEPTO: entidad inmutable (como NutritionPlan, ADR 0019)
 * Cada transición devuelve una versión NUEVA con el mismo id, que el caso de
 * uso guarda en el repositorio. Nadie ve cambiar «por detrás» una
 * importación que tenga en la mano.
 *
 * DECISIÓN: confirmar es del caso de uso; el agregado solo lo anota
 * Crear el NutritionPlan lo hace RegisterNutritionPlan (con el repositorio
 * de planes y el recetario, que el dominio no ve). El agregado dice SI se
 * puede confirmar (selfWeekForConfirmation()) y, cuando ya está creado el
 * plan, lo apunta (confirm()). Dos agregados distintos, y cada uno guarda sus
 * propias reglas.
 *
 * INVARIANTES:
 *   1. Siempre tiene el Excel de la nutricionista: se empieza con él.
 *   2. Una importación confirmada tiene la semana 2 (no se confirma sin
 *      ella) y el id del plan que se creó.
 *   3. Una importación confirmada no cambia ni se vuelve a confirmar.
 *
 * CAPA: Dominio (MealPlanning\Domain).
 */
final readonly class PlanImport
{
    private function __construct(
        private PlanImportId $id,
        private ImportedNutritionistPlan $nutritionistPlan,
        private ?ImportedSelfWeek $selfWeek,
        private ?NutritionPlanId $plan,
    ) {}

    /**
     * Empieza una importación con el Excel de la nutricionista: es siempre el
     * primero que llega (decisión de negocio del nivel 7).
     */
    public static function start(PlanImportId $id, ImportedNutritionistPlan $nutritionistPlan): self
    {
        return new self($id, $nutritionistPlan, null, null);
    }

    /**
     * Una importación en cualquier estado, comprobando sus invariantes. La
     * usa el repositorio para reconstruirla (como NutritionPlan::create(),
     * ADR 0006): una fila incoherente no se convierte en una importación
     * imposible circulando por la aplicación.
     *
     * @throws InvalidPlanImport si está confirmada sin semana 2
     */
    public static function create(
        PlanImportId $id,
        ImportedNutritionistPlan $nutritionistPlan,
        ?ImportedSelfWeek $selfWeek,
        ?NutritionPlanId $plan,
    ): self {
        if ($plan !== null && $selfWeek === null) {
            throw InvalidPlanImport::confirmedWithoutSelfWeek($nutritionistPlan->number());
        }

        return new self($id, $nutritionistPlan, $selfWeek, $plan);
    }

    // --- Transiciones ---------------------------------------------------------

    /**
     * Sustituye el Excel de la nutricionista (por ejemplo, porque se corrigió
     * un error). La semana 2, si ya estaba, se conserva.
     *
     * @throws InvalidPlanImport si ya está confirmada
     */
    public function replaceNutritionistPlan(ImportedNutritionistPlan $nutritionistPlan): self
    {
        $this->ensureNotConfirmed();

        return new self($this->id, $nutritionistPlan, $this->selfWeek, null);
    }

    /**
     * Añade la semana 2 o, si ya tenía una, la sustituye.
     *
     * @throws InvalidPlanImport si ya está confirmada
     */
    public function attachSelfWeek(ImportedSelfWeek $selfWeek): self
    {
        $this->ensureNotConfirmed();

        return new self($this->id, $this->nutritionistPlan, $selfWeek, null);
    }

    /**
     * La semana 2 con la que se va a confirmar la importación. Es la
     * pregunta «¿se puede confirmar?» del negocio: si no se puede, lo dice
     * con su error, ANTES de que el caso de uso cree el plan.
     *
     * Devuelve la semana (y no un bool) para que quien confirma la tenga ya
     * comprobada: con un canBeConfirmed() + selfWeek() tendría que volver a
     * mirar si es null.
     *
     * @throws InvalidPlanImport si ya está confirmada o le falta la semana 2
     */
    public function selfWeekForConfirmation(): ImportedSelfWeek
    {
        $this->ensureNotConfirmed();

        return $this->selfWeek ?? throw InvalidPlanImport::missingSelfWeek($this->number());
    }

    /**
     * Anota que la importación se ha convertido en ese plan. Desde aquí, ya
     * no cambia.
     *
     * @throws InvalidPlanImport si ya está confirmada o le falta la semana 2
     */
    public function confirm(NutritionPlanId $plan): self
    {
        $selfWeek = $this->selfWeekForConfirmation();

        return new self($this->id, $this->nutritionistPlan, $selfWeek, $plan);
    }

    // --- Lectura --------------------------------------------------------------

    public function id(): PlanImportId
    {
        return $this->id;
    }

    /**
     * El número del plan que se está importando (el del Excel de la
     * nutricionista).
     */
    public function number(): PlanNumber
    {
        return $this->nutritionistPlan->number();
    }

    public function nutritionistPlan(): ImportedNutritionistPlan
    {
        return $this->nutritionistPlan;
    }

    public function selfWeek(): ?ImportedSelfWeek
    {
        return $this->selfWeek;
    }

    /**
     * El plan que se creó al confirmarla, o null si aún no está confirmada.
     */
    public function plan(): ?NutritionPlanId
    {
        return $this->plan;
    }

    /**
     * El estado se deduce de lo que tiene (ver PlanImportStatus).
     */
    public function status(): PlanImportStatus
    {
        return match (true) {
            $this->plan !== null => PlanImportStatus::Confirmed,
            $this->selfWeek !== null => PlanImportStatus::ReadyToConfirm,
            default => PlanImportStatus::AwaitingSelfWeek,
        };
    }

    /**
     * Entidad: dos importaciones son la misma si tienen el mismo id.
     */
    public function equals(self $other): bool
    {
        return $this->id->equals($other->id);
    }

    /**
     * @throws InvalidPlanImport
     */
    private function ensureNotConfirmed(): void
    {
        if ($this->plan !== null) {
            throw InvalidPlanImport::alreadyConfirmed($this->number());
        }
    }
}
