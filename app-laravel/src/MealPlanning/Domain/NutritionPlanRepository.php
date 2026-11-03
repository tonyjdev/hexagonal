<?php

declare(strict_types=1);

namespace MealPlanning\Domain;

use DateTimeImmutable;

/**
 * NutritionPlanRepository: dónde se guardan los planes nutricionales y cómo
 * se encuentra el que está vigente en una fecha.
 *
 * CONCEPTO: puerto de salida (como Recipes\Domain\RecipeRepository)
 * Una interfaz del dominio que dice QUÉ necesita el núcleo, no CÓMO se hace.
 * Hoy la implementa InMemoryNutritionPlanRepository; en el nivel 5, un
 * adaptador con Eloquent y PostgreSQL. Los tests de contrato del puerto
 * (NutritionPlanRepositoryContractTest) fijan el comportamiento que deben
 * cumplir los dos.
 *
 * CONCEPTO: reglas entre agregados
 * Un agregado solo garantiza lo que hay dentro de su frontera (ADR 0010).
 * "¿Qué plan está vigente el día X?" y "no puede haber dos planes que
 * empiecen el mismo día" comparan VARIOS planes, así que ningún
 * NutritionPlan puede responderlas solo. Las responde quien ve todos los
 * planes: el repositorio. Por eso esas dos reglas forman parte del CONTRATO
 * de este puerto, escrito aquí y comprobado en los tests de contrato, y cada
 * adaptador las implementa a su manera (un bucle en PHP, un ORDER BY y un
 * índice único en SQL).
 *
 * CONCEPTO: consulta con nombre del negocio
 * inForceOn() no es un findBy(['starts_on' => ...]) genérico: es la pregunta
 * tal como la hace el negocio ("el plan vigente el día X"). El caso de uso
 * (HEXM-0019) no tiene que saber CÓMO se calcula la vigencia.
 *
 * CAPA: Dominio (MealPlanning\Domain).
 */
interface NutritionPlanRepository
{
    /**
     * Guarda el plan entero (nuevo o una versión nueva del mismo plan),
     * reemplazando la anterior si ya existía uno con el mismo id.
     *
     * Rechaza con DuplicatedPlanStart otro plan (distinto id) que empiece el
     * mismo día que uno ya guardado: así nunca hay dos planes vigentes a la
     * vez (regla 5). Decisión de HEXM-0018.
     *
     * Rechaza con DuplicatedPlanNumber otro plan (distinto id) con el mismo
     * número que uno ya guardado (regla 22, HEXM-0023). Si un plan choca por
     * las dos cosas, gana DuplicatedPlanStart: así todos los adaptadores dan
     * el mismo error para el mismo caso.
     *
     * @throws DuplicatedPlanStart
     * @throws DuplicatedPlanNumber
     */
    public function save(NutritionPlan $plan): void;

    /**
     * El plan con ese id, o null si no existe.
     */
    public function find(NutritionPlanId $id): ?NutritionPlan;

    /**
     * El plan vigente en una fecha, o null si no hay ninguno.
     *
     * Vigencia derivada (ADR 0010): está vigente el plan cuya fecha de
     * inicio es la más reciente de las que NO pasan de esa fecha. Se
     * comparan FECHAS DEL CALENDARIO (año-mes-día de cada una, sin la hora),
     * igual que MealCalendar: el lunes de inicio a las 00:00 o a las 23:59
     * es el mismo día.
     */
    public function inForceOn(DateTimeImmutable $date): ?NutritionPlan;

    /**
     * Todos los planes guardados, del más reciente al más antiguo (por fecha
     * de inicio). Lista vacía si no hay ninguno.
     *
     * Devuelve los agregados completos y no un resumen (decisión de
     * HEXM-0023): una persona tiene unos pocos planes al año, y así el
     * puerto no necesita un segundo modelo de lectura (CQS y no CQRS, como
     * el recetario, ADR 0008). Los periodos de vigencia NO los calcula el
     * repositorio: los deriva NutritionPlanHistory, en el dominio.
     *
     * @return list<NutritionPlan>
     */
    public function history(): array;
}
