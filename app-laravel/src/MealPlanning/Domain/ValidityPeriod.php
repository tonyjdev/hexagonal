<?php

declare(strict_types=1);

namespace MealPlanning\Domain;

use DateTimeImmutable;

/**
 * ValidityPeriod: el periodo de vigencia de un plan, del día en que empieza
 * al último día en que rige (o sin fin, si es el más reciente).
 *
 *     plan 26: 07/09/2026 → 01/11/2026   (el 02/11 empieza el 27)
 *     plan 27: 02/11/2026 → …            (vigente hasta que llegue otro)
 *
 * CONCEPTO: value object de periodo
 * Un rango de fechas con nombre del negocio y sus preguntas: ¿está vigente
 * tal día? ¿ha terminado ya? ¿todavía no ha empezado? Sin él, cada caso de
 * uso compararía fechas a mano, y el "último día" (¿inclusivo o no?) se
 * acabaría interpretando de dos formas.
 *
 * CONCEPTO: dato derivado, no guardado (ADR 0010)
 * El fin NO se guarda en ningún sitio: se deriva del inicio del plan
 * siguiente (NutritionPlanHistory). Así no puede quedar desincronizado al
 * registrar un plan nuevo o corregir una fecha.
 *
 * Las fechas son del calendario (a medianoche) y las comparaciones, por
 * año-mes-día en su propia zona horaria, como en MealCalendar: la hora y la
 * zona de quien pregunta no cambian la respuesta.
 *
 * CAPA: Dominio (MealPlanning\Domain).
 */
final readonly class ValidityPeriod
{
    private function __construct(
        private DateTimeImmutable $startsOn,
        private ?DateTimeImmutable $endsOn,
    ) {}

    /**
     * Un periodo cerrado: rige del inicio hasta endsOn, ambos incluidos.
     */
    public static function between(DateTimeImmutable $startsOn, DateTimeImmutable $endsOn): self
    {
        return new self($startsOn->setTime(0, 0), $endsOn->setTime(0, 0));
    }

    /**
     * Un periodo abierto: rige desde el inicio y sin fin conocido.
     */
    public static function from(DateTimeImmutable $startsOn): self
    {
        return new self($startsOn->setTime(0, 0), null);
    }

    public function startsOn(): DateTimeImmutable
    {
        return $this->startsOn;
    }

    /**
     * El último día en que rige (inclusivo), o null si no tiene fin.
     */
    public function endsOn(): ?DateTimeImmutable
    {
        return $this->endsOn;
    }

    /**
     * ¿Rige ese día? Del inicio al último día, ambos incluidos.
     */
    public function includes(DateTimeImmutable $date): bool
    {
        return ! $this->startsAfter($date) && ! $this->hasEndedBefore($date);
    }

    /**
     * ¿Ya había terminado antes de ese día?
     */
    public function hasEndedBefore(DateTimeImmutable $date): bool
    {
        return $this->endsOn !== null && self::day($this->endsOn) < self::day($date);
    }

    /**
     * ¿Empieza después de ese día (todavía no ha empezado)?
     */
    public function startsAfter(DateTimeImmutable $date): bool
    {
        return self::day($this->startsOn) > self::day($date);
    }

    /**
     * 'AAAA-MM-DD': comparar estos textos ordena igual que las fechas.
     */
    private static function day(DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d');
    }
}
