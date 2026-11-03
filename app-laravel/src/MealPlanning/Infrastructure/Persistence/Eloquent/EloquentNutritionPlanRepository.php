<?php

declare(strict_types=1);

namespace MealPlanning\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use MealPlanning\Domain\DuplicatedPlanStart;
use MealPlanning\Domain\NutritionPlan;
use MealPlanning\Domain\NutritionPlanId;
use MealPlanning\Domain\NutritionPlanRepository;

/**
 * EloquentNutritionPlanRepository: guarda los planes nutricionales en
 * PostgreSQL, una fila por plan (ADR 0013).
 *
 * CONCEPTO: segundo adaptador para el mismo puerto
 * Implementa NutritionPlanRepository, como InMemoryNutritionPlanRepository,
 * y pasa los mismos tests de contrato (NutritionPlanRepositoryContractTest).
 * GetMenuForDate no cambia ni una línea: le da igual qué adaptador reciba.
 *
 * CONCEPTO: las reglas del contrato, a la manera de SQL (ADR 0011)
 *   - La vigencia: WHERE starts_on <= fecha ORDER BY starts_on DESC LIMIT 1.
 *     El adaptador en memoria hace lo mismo con un bucle.
 *   - "Dos planes no empiezan el mismo día": un índice único en starts_on.
 *     Lo cumple PostgreSQL, también con dos guardados a la vez. Aquí solo se
 *     traduce su violación (un error técnico) a la excepción del dominio.
 *
 * CAPA: Infraestructura (MealPlanning\Infrastructure).
 */
final readonly class EloquentNutritionPlanRepository implements NutritionPlanRepository
{
    public function __construct(private NutritionPlanMapper $mapper = new NutritionPlanMapper) {}

    /**
     * CONCEPTO: atomicidad con una sola fila
     * En el recetario, una receta son filas en tres tablas, y la transacción
     * evita guardar media receta (ADR 0006). Aquí el plan entero es UNA fila:
     * updateOrCreate() acaba en un único INSERT o UPDATE, y PostgreSQL ya
     * hace atómica cada sentencia. No puede quedar un plan a medias ni filas
     * huérfanas que limpiar.
     *
     * CONCEPTO: por qué hay transacción igualmente (SAVEPOINT)
     * En PostgreSQL, un error dentro de una transacción la deja ABORTADA:
     * cualquier consulta posterior falla con "current transaction is
     * aborted" hasta el ROLLBACK. Si save() se llama dentro de otra
     * transacción (la de RefreshDatabase en los tests, o la de un caso de
     * uso del nivel 5) y salta el índice único, no se podría consultar
     * después qué plan ocupa ese día para escribir el mensaje. transaction()
     * anidada se convierte en un SAVEPOINT; al fallar, se deshace HASTA el
     * savepoint y la transacción de fuera sigue siendo usable. Sin
     * transacción de fuera, es una transacción normal.
     *
     * Comprobado en HEXM-0020, quitando la transacción:
     *   - un plan NUEVO en un día ocupado (INSERT) seguía funcionando, porque
     *     updateOrCreate() crea con createOrFirst(), que ya envuelve el
     *     INSERT en un savepoint (Laravel lo hace por este mismo problema);
     *   - un plan GUARDADO movido a un día ocupado (UPDATE) fallaba con
     *     "SQLSTATE[25P02] current transaction is aborted": ese camino solo
     *     lo protege esta transacción. Lo cubre el test de contrato
     *     "rejects moving a saved plan to a day on which another plan starts".
     *
     * @throws DuplicatedPlanStart
     */
    public function save(NutritionPlan $plan): void
    {
        $row = $this->mapper->toRow($plan);

        try {
            // La transacción en la conexión del modelo (como en el recetario).
            (new NutritionPlanModel)->getConnection()->transaction(function () use ($row): void {
                NutritionPlanModel::query()->updateOrCreate(['id' => $row['id']], $row);
            });
        } catch (UniqueConstraintViolationException $violation) {
            // Ya fuera del savepoint deshecho: la conexión vuelve a estar
            // usable y se puede buscar el plan que ocupa ese día.
            throw DuplicatedPlanStart::between($plan, $this->startingOn($row['starts_on']) ?? throw $violation);
        }
    }

    public function find(NutritionPlanId $id): ?NutritionPlan
    {
        $model = NutritionPlanModel::query()->find($id->value());

        return $model === null ? null : $this->mapper->toDomain($model);
    }

    /**
     * Compara fechas del calendario: starts_on es una columna date y la fecha
     * consultada se reduce a 'AAAA-MM-DD' en su propia zona horaria (como
     * MealCalendar y el adaptador en memoria). Las 00:30 del día 2 en Madrid
     * son el día 2, aunque en UTC aún sea el 1.
     */
    public function inForceOn(DateTimeImmutable $date): ?NutritionPlan
    {
        $model = NutritionPlanModel::query()
            ->where('starts_on', '<=', $date->format('Y-m-d'))
            ->orderByDesc('starts_on')
            ->first();

        return $model === null ? null : $this->mapper->toDomain($model);
    }

    /**
     * El plan que ya empieza ese día, para el mensaje de DuplicatedPlanStart.
     * Si no hay ninguno, la violación era de otro índice único (hoy no hay
     * otro), y se relanza tal cual: no se disfraza un error que no se
     * entiende.
     */
    private function startingOn(string $date): ?NutritionPlan
    {
        $model = NutritionPlanModel::query()->where('starts_on', $date)->first();

        return $model === null ? null : $this->mapper->toDomain($model);
    }
}
