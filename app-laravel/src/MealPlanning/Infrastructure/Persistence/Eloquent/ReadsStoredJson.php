<?php

declare(strict_types=1);

namespace MealPlanning\Infrastructure\Persistence\Eloquent;

use UnexpectedValueException;

/**
 * ReadsStoredJson: lectura defensiva de un documento JSON guardado en
 * PostgreSQL, para los mappers de MealPlanning.
 *
 * CONCEPTO: el JSON guardado es una entrada NO confiable (ADR 0013)
 * Dentro de un jsonb, PostgreSQL no garantiza tipos: un UPDATE a mano o una
 * migración mal hecha pueden dejar un "choose": "dos" o quitar un campo. Por
 * eso, al leer, cada valor se comprueba antes de dárselo al dominio, y si
 * algo no cuadra se lanza UnexpectedValueException con la RUTA exacta del
 * dato corrupto ("weeks[1].days[2].lunch.name").
 *
 * CONCEPTO: narrowing de tipos (estrechar un mixed)
 * json_decode devuelve `mixed`: PHPStan (y nosotros) no sabemos qué hay.
 * Cada función comprueba el tipo en tiempo de ejecución y devuelve un tipo
 * concreto, o lanza la excepción. A partir de ahí, el resto del código
 * trabaja con tipos seguros.
 *
 * CONCEPTO: trait para compartir código sin herencia
 * Nacieron en NutritionPlanMapper (HEXM-0020) y desde HEXM-0032 los necesita
 * también PlanImportMapper. Un trait «copia» estos métodos en cada clase que
 * lo usa: los dos mappers siguen siendo final, sin una clase base común que
 * no representa nada del negocio. Cada uno dice qué es lo que está corrupto
 * (corruptionPrefix()) para que el mensaje lo nombre.
 *
 * CAPA: Infraestructura (MealPlanning\Infrastructure).
 */
trait ReadsStoredJson
{
    /**
     * El principio del mensaje de error, con su concordancia: «Plan guardado
     * corrupto», «Importación guardada corrupta»…
     */
    abstract private static function corruptionPrefix(): string;

    /**
     * Una lista JSON ([...]): claves 0, 1, 2... sin huecos.
     *
     * @return list<mixed>
     */
    private static function list(mixed $value, string $path): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw self::corrupted($path, 'una lista');
        }

        return $value;
    }

    /**
     * Un objeto JSON ({...}), que PHP decodifica como array asociativo.
     *
     * @return array<string, mixed>
     */
    private static function object(mixed $value, string $path): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw self::corrupted($path, 'un objeto');
        }

        $object = [];

        foreach ($value as $key => $item) {
            $object[(string) $key] = $item;
        }

        return $object;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value, string $path): array
    {
        $strings = [];

        foreach (self::list($value, $path) as $i => $item) {
            $strings[] = self::string($item, "{$path}[{$i}]");
        }

        return $strings;
    }

    private static function string(mixed $value, string $path): string
    {
        if (! is_string($value)) {
            throw self::corrupted($path, 'un texto');
        }

        return $value;
    }

    private static function nullableString(mixed $value, string $path): ?string
    {
        return $value === null ? null : self::string($value, $path);
    }

    private static function int(mixed $value, string $path): int
    {
        if (! is_int($value)) {
            throw self::corrupted($path, 'un número entero');
        }

        return $value;
    }

    /**
     * Un caso de un enum respaldado (backed enum), a partir de su valor.
     *
     * CONCEPTO: genéricos en PHPDoc (@template)
     * PHP no tiene genéricos, pero PHPStan sí los entiende en los comentarios:
     * con enum(MealType::class, ...), sabe que lo que vuelve es un MealType.
     *
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T
     */
    private static function enum(string $enum, mixed $value, string $path): \BackedEnum
    {
        // tryFrom() devuelve null si el valor no es un caso del enum (from()
        // lanzaría un ValueError sin decir qué dato era).
        $case = is_int($value) || is_string($value) ? $enum::tryFrom($value) : null;

        if ($case === null) {
            throw self::corrupted($path, "un valor de {$enum}");
        }

        return $case;
    }

    /**
     * No es un error del usuario ni una regla de negocio: son datos que
     * nunca debieron llegar a la base de datos. Por eso no es una
     * DomainException (que la API mostraría como 422), sino una excepción
     * técnica.
     */
    private static function corrupted(string $path, string $expected): UnexpectedValueException
    {
        return new UnexpectedValueException(sprintf('%s: «%s» debería ser %s.', self::corruptionPrefix(), $path, $expected));
    }
}
