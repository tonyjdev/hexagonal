<?php

declare(strict_types=1);

namespace MealPlanning\Application;

use MealPlanning\Domain\RecipeReference;

/**
 * RecipeCatalog: lo que MealPlanning necesita saber del recetario.
 *
 *     «¿existe en el recetario la receta a la que apunta este plato?»
 *
 * CONCEPTO: puerto propio hacia otro bounded context (dependencia invertida)
 * MealPlanning no puede importar nada de Recipes (lo prohíben los tests de
 * arquitectura). En vez de llamar a Recipes, DEFINE la pregunta que necesita,
 * en su propio idioma (RecipeReference, no RecipeId), y alguien de fuera la
 * responde: un adaptador de MealPlanning\Infrastructure que traduce la
 * pregunta al otro contexto. Es la misma inversión de dependencias que con la
 * base de datos (NutritionPlanRepository), pero el "sistema externo" es otro
 * contexto de la misma aplicación.
 *
 *     MealPlanning\Application ──► RecipeCatalog ◄── adaptador (Infrastructure) ──► Recipes\Application
 *
 * Mañana Recipes podría ser un microservicio, o un servicio de Python: solo
 * cambiaría el adaptador.
 *
 * DECISIÓN: el puerto vive en Application, no en Domain (HEXM-0025, ADR 0017)
 * Lo usa solo el caso de uso RegisterNutritionPlan: preguntar por el estado
 * de OTRO sistema es orquestación, como pedir la fecha de hoy al Clock
 * (ADR 0012). El dominio (Dish, NutritionPlan) sigue siendo puro: recibe la
 * referencia y no hace preguntas al exterior. NutritionPlanRepository sí
 * está en Domain porque guarda los agregados del propio contexto y su
 * contrato incluye reglas entre planes (ADR 0011); aquí no hay nada de eso.
 *
 * DECISIÓN: devuelve un bool, lo mínimo
 * El plan solo necesita saber si la receta existe (regla 6). Devolver la
 * receta (o una vista suya) invitaría a copiar sus datos en el plato, algo
 * fuera de alcance (nivel 10) que además acoplaría los dos modelos. Si algún
 * día el plan necesita más (el nombre de la receta, por ejemplo), el puerto
 * crecerá con un método nuevo y un DTO PROPIO de MealPlanning.
 *
 * CAPA: Aplicación (MealPlanning\Application): puerto de salida.
 */
interface RecipeCatalog
{
    /**
     * Si el recetario tiene una receta con ese id. Una consulta: no cambia
     * nada (CQS).
     */
    public function exists(RecipeReference $recipe): bool;
}
