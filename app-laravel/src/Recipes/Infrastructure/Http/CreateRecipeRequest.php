<?php

declare(strict_types=1);

namespace Recipes\Infrastructure\Http;

use Illuminate\Foundation\Http\FormRequest;
use Recipes\Application\CreateRecipeCommand;

/**
 * CreateRecipeRequest: valida la FORMA de la petición POST /api/recipes y la
 * traduce al comando del caso de uso.
 *
 * CONCEPTO: validación de forma (frontera) frente a reglas de negocio (dominio)
 * Hay dos preguntas distintas, y cada una tiene su sitio:
 *
 *   1. "¿La petición cumple el contrato de la API?" → aquí.
 *      Que estén los campos y que tengan el tipo esperado: servings es un
 *      entero, ingredients es una lista, amount es texto...
 *   2. "¿El negocio permite esta receta?" → en el dominio.
 *      Al menos un ingrediente, al menos una ración, cantidad mayor que cero,
 *      unidad conocida, sin ingredientes repetidos...
 *
 * Por eso aquí NO hay `min:1` en servings, ni `in:gram,jar,...` en unit, ni
 * `required` en ingredients (rechazaría la lista vacía): esas reglas ya
 * viven en Servings, Unit y Recipe. Si se repitieran aquí, habría dos fuentes
 * de verdad que acabarían divergiendo, y el comando de Artisan o el servicio
 * de Python que creen recetas mañana tendrían que volver a repetirlas. Las
 * reglas de negocio se escriben UNA vez, en el dominio, y protegen a todos
 * los adaptadores de entrada.
 *
 * Criterio práctico:
 *   - falta un campo o tiene un tipo incorrecto → 422 de validación de
 *     Laravel ({"message": ..., "errors": {...}}), sin llegar al caso de uso;
 *   - el campo está, pero su valor incumple una regla → 422 del dominio
 *     ({"message": "..."} en español, ver bootstrap/app.php).
 *
 * CONCEPTO: Form Request
 * En Laravel, una FormRequest es una clase que valida la petición ANTES de
 * llegar al controlador: si falla, Laravel responde 422 por su cuenta y el
 * controlador ni se ejecuta. Es el lugar natural para la validación de forma,
 * y vive en Infrastructure porque es una pieza de HTTP.
 *
 * CONCEPTO: anticorrupción en pequeño (toCommand)
 * El controlador no maneja arrays de la petición: pide a esta clase el
 * CreateRecipeCommand ya construido. Así la traducción "JSON de la API →
 * comando de la aplicación" está en un único método, y si el contrato de la
 * API cambia (por ejemplo, renombrar un campo), el caso de uso no se entera.
 *
 * CAPA: Infraestructura, adaptador de entrada HTTP (Recipes\Infrastructure\Http).
 */
final class CreateRecipeRequest extends FormRequest
{
    /**
     * Sin autenticación por ahora (fuera del alcance de HEXM-0010).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Contrato de la petición:
     *
     *   {
     *     "name": "Pisto",
     *     "servings": 2,
     *     "ingredients": [
     *       {"name": "Calabacín", "amount": "1", "unit": "piece"},
     *       {"name": "Sal", "amount": null, "unit": null}
     *     ],
     *     "steps": ["Picar las verduras", "Pochar a fuego lento"]
     *   }
     *
     * - `present` exige que el campo exista, pero admite que esté vacío:
     *   que no esté vacío lo decide el dominio con su mensaje de negocio.
     * - `nullable` es necesario porque el middleware ConvertEmptyStringsToNull
     *   de Laravel convierte "" en null (ver toCommand()).
     * - `list` exige un array con índices 0, 1, 2... (un array JSON, no un
     *   objeto), que es lo que espera el comando.
     * - amount es TEXTO, no número: admite "½", "1 ½" o "1,5". Quantity la
     *   interpreta; aquí solo se exige que sea una cadena.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['present', 'nullable', 'string'],
            'servings' => ['required', 'integer'],
            'ingredients' => ['present', 'array', 'list'],
            'ingredients.*' => ['array'],
            'ingredients.*.name' => ['present', 'nullable', 'string'],
            'ingredients.*.amount' => ['nullable', 'string'],
            'ingredients.*.unit' => ['nullable', 'string'],
            'steps' => ['sometimes', 'array', 'list'],
            'steps.*' => ['nullable', 'string'],
        ];
    }

    /**
     * Traduce la petición ya validada al comando del caso de uso.
     *
     * CONCEPTO: deshacer ConvertEmptyStringsToNull
     * Laravel convierte cada "" de la petición en null antes de validarla.
     * Si se dejara así, un nombre vacío llegaría como null al caso de uso
     * (que espera string) y el error sería técnico, no de negocio. Con
     * `?? ''` se devuelve la cadena vacía y es el DOMINIO quien la rechaza
     * con su mensaje ("Una receta debe tener nombre"). En amount y unit,
     * en cambio, null significa "al gusto", así que se deja tal cual.
     */
    public function toCommand(): CreateRecipeCommand
    {
        // La validación garantiza esta forma; la anotación se la cuenta a
        // PHPStan, que no puede deducirla de las reglas.
        /** @var array{name: string|null, servings: int|string, ingredients: list<array{name: string|null, amount?: string|null, unit?: string|null}>, steps?: list<string|null>} $data */
        $data = $this->validated();

        return new CreateRecipeCommand(
            name: $data['name'] ?? '',
            // integer acepta 2 y "2" (un formulario envía texto): se convierte.
            servings: (int) $data['servings'],
            ingredients: array_map(
                fn (array $ingredient): array => [
                    'name' => $ingredient['name'] ?? '',
                    'amount' => $ingredient['amount'] ?? null,
                    'unit' => $ingredient['unit'] ?? null,
                ],
                $data['ingredients'],
            ),
            steps: array_map(fn (?string $step): string => $step ?? '', $data['steps'] ?? []),
        );
    }
}
