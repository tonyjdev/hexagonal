<?php

declare(strict_types=1);

namespace MealPlanning\Domain;

/**
 * MenuWeek: una de las dos semanas de comidas y cenas de un plan.
 *
 * CONCEPTO: entidad local dentro de un agregado
 * El agregado será NutritionPlan (HEXM-0015), que tendrá exactamente dos
 * semanas. Dentro del plan, cada semana tiene IDENTIDAD LOCAL: su número
 * (1 o 2). "La semana 2 del plan 27" sigue siendo la misma aunque el usuario
 * le cambie platos (nivel 8). Esa identidad solo tiene sentido dentro de su
 * plan (la semana 1 del plan 26 y la del plan 27 no son "la misma"), así que
 * no tiene un id global como NutritionPlanId, y de momento tampoco equals():
 * se añadirá cuando alguien necesite comparar semanas.
 *
 * Hoy no hay ningún comportamiento que la cambie, así que es readonly. Cuando
 * llegue la edición de la semana 2, se decidirá cómo (por ejemplo,
 * replaceDay() devolviendo la semana nueva, o mutándola a través del plan).
 *
 * CONCEPTO: invariantes sobre una colección
 * Las reglas no miran un día suelto, sino el CONJUNTO de días:
 *   - CARDINALIDAD: exactamente 6 días con menú (regla 2).
 *   - UNICIDAD: ningún día de la semana se repite.
 *   - HUECO PERMITIDO: solo el sábado (día libre) se queda sin menú. Eso ya
 *     lo garantiza MenuDay, que no admite un sábado. Con 6 días distintos y
 *     ninguno en sábado, por fuerza son de lunes a viernes y el domingo: no
 *     hace falta comprobarlo aparte.
 *
 * CONCEPTO: traducir formatos de origen a un modelo único
 * Cada documento numera los días a su manera (DOMINIO.md, "Calendario"):
 *   - semana 1 (nutricionista): menús 1 a 6, sin decir el día; se asignan en
 *     orden saltando el sábado (menú 6 → domingo);
 *   - semana 2 (usuario): días 8 a 14, con el 13 (sábado) vacío.
 * Esas numeraciones son detalles del FORMATO. El modelo del dominio solo
 * habla de días de la semana (Weekday). Los constructores con nombre
 * fromNutritionistMenus() y fromSelfDays() hacen la traducción y luego pasan
 * por create(), que es el único sitio donde se comprueban las reglas.
 * La traducción vive en el dominio (y no en el futuro importador) porque
 * "el menú 6 es el domingo" es una regla del NEGOCIO, no del Excel.
 *
 * CONCEPTO: colección inmutable (igual que Recipe con sus ingredientes)
 * Los días se guardan en una propiedad readonly y days() devuelve una copia
 * del array: modificarla no cambia la semana. Y cada MenuDay es un value
 * object inmutable, así que tampoco se puede cambiar un día "por dentro".
 *
 * CAPA: Dominio (MealPlanning\Domain).
 */
final readonly class MenuWeek
{
    private const int DAYS_WITH_MENU = 6;

    /**
     * Privado: solo se crea con create() o los constructores de formato.
     *
     * @param  list<MenuDay>  $days  ordenados de lunes a domingo
     */
    private function __construct(
        private int $number,
        private WeekSource $source,
        private array $days,
    ) {}

    /**
     * Crea una semana a partir de sus días, en cualquier orden, comprobando
     * todas sus invariantes.
     *
     * DECISIÓN: no se exige que la semana 1 sea de la nutricionista y la 2
     * del usuario. Hoy siempre es así, pero es una costumbre del documento
     * más que una regla que haya pedido el negocio; los constructores de
     * formato ya fijan la pareja correcta.
     *
     * @param  array<MenuDay>  $days
     */
    public static function create(int $number, WeekSource $source, array $days): self
    {
        if ($number !== 1 && $number !== 2) {
            throw InvalidMenuWeek::unknownNumber($number);
        }

        $days = array_values($days);

        // Primero la unicidad y luego la cantidad: con dos lunes y ningún
        // martes hay 6 días, y el mensaje útil es "el lunes está repetido".
        self::guardAgainstDuplicatedDays($days);

        if (count($days) !== self::DAYS_WITH_MENU) {
            throw InvalidMenuWeek::wrongNumberOfDays(count($days));
        }

        // Orden canónico (lunes → domingo), venga como venga: así days() se
        // lee igual que el calendario. <=> es el "operador nave espacial".
        usort($days, fn (MenuDay $a, MenuDay $b) => $a->weekday()->value <=> $b->weekday()->value);

        return new self($number, $source, $days);
    }

    /**
     * Semana 1, tal como la entrega la nutricionista: menús numerados del
     * 1 al 6, cada uno con su comida y su cena.
     *
     *     MenuWeek::fromNutritionistMenus([
     *         1 => ['lunch' => $lentejas, 'dinner' => $merluza],
     *         ...
     *         6 => ['lunch' => ..., 'dinner' => ...],   // → domingo
     *     ]);
     *
     * Si a un menú le falta la comida o la cena, lo dice MenuDay.
     *
     * @param  array<int, array{lunch?: ?Dish, dinner?: ?Dish}>  $menus  clave = número de menú
     */
    public static function fromNutritionistMenus(array $menus): self
    {
        // El menú N va al N-ésimo día CON MENÚ de la semana: lunes, martes,
        // miércoles, jueves, viernes, (sábado no) y domingo. Se calcula a
        // partir de Weekday en vez de escribir "6 => domingo" a mano: así la
        // regla "saltando el día libre" se lee en el propio código.
        $weekdaysWithMenu = array_values(array_filter(
            Weekday::cases(),
            fn (Weekday $weekday) => ! $weekday->isFreeDay(),
        ));

        $days = [];

        foreach ($menus as $menu => $meals) {
            $weekday = $weekdaysWithMenu[$menu - 1] ?? throw InvalidMenuWeek::unknownNutritionistMenu($menu);

            $days[] = MenuDay::of($weekday, $meals['lunch'] ?? null, $meals['dinner'] ?? null);
        }

        return self::create(1, WeekSource::Nutritionist, $days);
    }

    /**
     * Semana 2, tal como la elabora el usuario: días numerados del 8 al 14
     * (8 = lunes ... 14 = domingo), con el 13 (sábado) libre.
     *
     *     MenuWeek::fromSelfDays([
     *         8 => ['lunch' => $pastaConSalmon, 'dinner' => $tortilla],
     *         ...
     *         13 => null,                               // sábado: vacío
     *         14 => ['lunch' => ..., 'dinner' => ...],
     *     ]);
     *
     * Un día a null (o ausente) es un día vacío, como el 13 del documento.
     * Si el que falta no es el sábado, lo detecta create() al contar los
     * días; si alguien rellena el 13, lo rechaza MenuDay.
     *
     * @param  array<int, array{lunch?: ?Dish, dinner?: ?Dish}|null>  $days  clave = número de día
     */
    public static function fromSelfDays(array $days): self
    {
        $menuDays = [];

        foreach ($days as $day => $meals) {
            // Día 8 → 1 (lunes) ... día 14 → 7 (domingo): el valor ISO de Weekday.
            $weekday = Weekday::tryFrom($day - 7) ?? throw InvalidMenuWeek::unknownSelfDay($day);

            if ($meals === null) {
                continue;
            }

            $menuDays[] = MenuDay::of($weekday, $meals['lunch'] ?? null, $meals['dinner'] ?? null);
        }

        return self::create(2, WeekSource::Self, $menuDays);
    }

    /**
     * Número de la semana dentro del plan (1 o 2): su identidad local.
     */
    public function number(): int
    {
        return $this->number;
    }

    public function source(): WeekSource
    {
        return $this->source;
    }

    /**
     * Los 6 días con menú, de lunes a domingo. Devuelve una COPIA del array.
     *
     * @return list<MenuDay>
     */
    public function days(): array
    {
        return $this->days;
    }

    /**
     * El menú de un día de la semana, o null si es el día libre.
     *
     * Es la pregunta que hará el calendario (nivel 4) una vez sepa qué
     * semana toca: "¿y qué hay el miércoles?".
     */
    public function day(Weekday $weekday): ?MenuDay
    {
        foreach ($this->days as $day) {
            if ($day->weekday() === $weekday) {
                return $day;
            }
        }

        return null;
    }

    /**
     * @param  list<MenuDay>  $days
     */
    private static function guardAgainstDuplicatedDays(array $days): void
    {
        $seen = [];

        foreach ($days as $day) {
            $weekday = $day->weekday();

            if (isset($seen[$weekday->value])) {
                throw InvalidMenuWeek::duplicatedDay($weekday);
            }

            $seen[$weekday->value] = true;
        }
    }
}
