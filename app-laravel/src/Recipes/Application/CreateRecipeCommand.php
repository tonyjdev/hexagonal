<?php

declare(strict_types=1);

namespace Recipes\Application;

/**
 * CreateRecipeCommand: los datos necesarios para crear una receta.
 *
 * CONCEPTO: Comando / DTO de entrada
 * Un DTO (Data Transfer Object) solo transporta datos: no tiene reglas ni
 * comportamiento. Este es el "formulario" que rellena quien quiera crear una
 * receta, y por eso habla en PRIMITIVOS (string, int, array), no en value
 * objects: así, cualquier adaptador de entrada (un controlador HTTP en el
 * nivel 2, un comando de Artisan, un test o un servicio de Python) puede
 * construirlo sin conocer el dominio. Convertir esos primitivos en Quantity,
 * Unit, Ingredient... es trabajo del caso de uso.
 * Se llama "comando" porque expresa una INTENCIÓN que cambia el sistema
 * ("crea esta receta"), frente a una consulta, que solo pregunta.
 *
 * En Laravel normalmente harías $request->validated() y pasarías el array al
 * modelo. Aquí el DTO tipa y documenta exactamente qué datos se esperan,
 * y el caso de uso no depende de Request (que es de Laravel).
 *
 * Los tipos de los arrays (array shapes) los comprueba PHPStan: PHP solo
 * sabe que son arrays.
 *
 * CAPA: Aplicación (Recipes\Application).
 */
final readonly class CreateRecipeCommand
{
    /**
     * Cada ingrediente: amount como lo escribe una persona ("½", "1,5",
     * "200") y unit con el valor de Unit ("gram", "jar"...). Si los dos son
     * null, el ingrediente es "al gusto". Los pasos, en orden.
     *
     * @param  list<array{name: string, amount: string|null, unit: string|null}>  $ingredients
     * @param  list<string>  $steps
     */
    public function __construct(
        public string $name,
        public int $servings,
        public array $ingredients,
        public array $steps = [],
    ) {}
}
