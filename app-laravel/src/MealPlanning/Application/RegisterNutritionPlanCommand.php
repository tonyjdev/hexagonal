<?php

declare(strict_types=1);

namespace MealPlanning\Application;

use DateTimeImmutable;

/**
 * RegisterNutritionPlanCommand: los datos de un plan nutricional para darlo
 * de alta, tal como vienen en los documentos.
 *
 * CONCEPTO: comando (como Recipes\Application\CreateRecipeCommand)
 * Un objeto inmutable que solo transporta datos HACIA el núcleo, en tipos
 * primitivos (int, string, arrays). No conoce HTTP ni JSON: lo puede crear
 * una Form Request, un comando de Artisan o, en el nivel 7, el importador de
 * Excel. Y no conoce el dominio: traducir estos datos al agregado es trabajo
 * del caso de uso (RegisterNutritionPlan).
 *
 * DECISIÓN: la forma de los documentos de origen (HEXM-0021, ADR 0014)
 * Cada semana llega en SU formato, sin traducir:
 *   - semana 1 (la nutricionista): menús numerados del 1 al 6, cada uno con
 *     la comida y la cena en texto libre;
 *   - semana 2 (el usuario): días numerados del 8 al 14, cada plato con un
 *     nombre corto y sus ingredientes; el 13 (sábado) vacío o ausente.
 * Así quien envía el plan copia lo que ve en el papel, y la traducción "el
 * menú 6 es el domingo" (una regla del negocio) se queda en el dominio, en
 * MenuWeek::fromNutritionistMenus() y fromSelfDays().
 *
 * DECISIÓN: la receta de cada plato, opcional (HEXM-0025, ADR 0017)
 * Un plato puede apuntar a una receta del recetario con su id (texto):
 *   - semana 1: lunch_recipe_id y dinner_recipe_id, claves HERMANAS de
 *     lunch y dinner, porque allí un plato es solo un texto y no tiene dónde
 *     llevarlo. Así el formato de siempre sigue valiendo tal cual;
 *   - semana 2: recipe_id dentro del objeto de cada plato.
 * Las claves son opcionales (`?:` en el tipo): quien no las conoce (el
 * importador del nivel 7, los tests anteriores) no tiene que enviarlas. El
 * id llega como texto: que sea un UUID lo dice el dominio (RecipeReference)
 * y que exista, el puerto RecipeCatalog.
 *
 * startsOn es un DateTimeImmutable y no un texto, como en GetMenuForDate
 * (HEXM-0019): es un tipo nativo de PHP, y convertir el texto de una
 * petición es trabajo del adaptador.
 *
 * CAPA: Aplicación (MealPlanning\Application).
 */
final readonly class RegisterNutritionPlanCommand
{
    /**
     * Los platos a null (o un nombre vacío) los deja pasar el comando: si a
     * un menú le falta la comida, lo dice el dominio, con su mensaje.
     *
     * @param  list<array{meal_type: string, description: string|null, option_groups: list<array{choose: int, options: list<string>}>}>  $fixedMeals
     * @param  list<array{category: string, food: string, serving: string}>  $exchanges
     * @param  list<string>  $exchangeRules  en el orden del documento
     * @param  list<array{menu: int, lunch: string|null, dinner: string|null, lunch_recipe_id?: string|null, dinner_recipe_id?: string|null}>  $nutritionistWeek
     * @param  list<array{day: int, lunch: array{name: string, ingredients: list<array{name: string, quantity: string|null}>, recipe_id?: string|null}|null, dinner: array{name: string, ingredients: list<array{name: string, quantity: string|null}>, recipe_id?: string|null}|null}>  $selfWeek
     */
    public function __construct(
        public int $number,
        public DateTimeImmutable $startsOn,
        public array $fixedMeals,
        public array $exchanges,
        public array $exchangeRules,
        public array $nutritionistWeek,
        public array $selfWeek,
    ) {}
}
