<?php

declare(strict_types=1);

namespace Recipes\Domain;

/**
 * RecipeRepository: dónde se guardan y se recuperan las recetas.
 *
 * CONCEPTO: Puerto de salida (driven port)
 * Es una INTERFAZ: dice QUÉ necesita el núcleo ("guárdame esta receta",
 * "dame la receta con este id"), no CÓMO se hace. El cómo lo pone un
 * ADAPTADOR en Infrastructure: hoy InMemoryRecipeRepository (para los tests),
 * en el nivel 2 uno con Eloquent y PostgreSQL. El caso de uso CreateRecipe
 * solo conoce esta interfaz, así que cambiar de base de datos no lo toca.
 *
 * CONCEPTO: Inversión de dependencias (la "D" de SOLID)
 * La llamada va hacia FUERA (el caso de uso pide guardar en la base de
 * datos), pero la dependencia del código apunta hacia DENTRO: es la
 * infraestructura la que importa e implementa esta interfaz del dominio, no
 * el dominio el que importa Eloquent. Por eso el puerto vive en Domain, y por
 * eso la regla de dependencias se cumple.
 *
 * CONCEPTO: Repositorio de agregados
 * Se guarda y se recupera la receta ENTERA (la raíz del agregado con sus
 * ingredientes y pasos), nunca un ingrediente suelto. Su lenguaje es el de
 * una colección de recetas, no el de una tabla: no hay update(), insert()
 * ni consultas SQL. save() vale tanto para una receta nueva como para una
 * modificada.
 *
 * Solo tiene los métodos que alguien usa hoy: guardar (CreateRecipe), buscar
 * (GetRecipe) y, desde HEXM-0026, borrar (DeleteRecipe). Crecerá con los
 * casos de uso que lo necesiten (listar...).
 *
 * CAPA: Dominio (Recipes\Domain).
 */
interface RecipeRepository
{
    /**
     * Guarda la receta (nueva o modificada), reemplazando la versión anterior
     * si ya existía una con el mismo RecipeId.
     */
    public function save(Recipe $recipe): void;

    /**
     * Devuelve la receta con ese identificador, o null si no existe.
     */
    public function find(RecipeId $id): ?Recipe;

    /**
     * Borra la receta con ese identificador, entera: con sus ingredientes y
     * sus pasos (el agregado se borra como una unidad, igual que se guarda).
     *
     * Si no existe, no hace nada: borrar es IDEMPOTENTE (repetirlo deja el
     * mismo resultado). Decir "no existe" es trabajo del caso de uso, que la
     * busca antes (RecipeNotFound); el repositorio no lanza nada.
     *
     * Recibe el id y no la Recipe: para borrar basta con saber cuál. Los
     * eventos que la receta haya registrado no son cosa del repositorio: los
     * publica el caso de uso (ADR 0018).
     */
    public function delete(RecipeId $id): void;
}
