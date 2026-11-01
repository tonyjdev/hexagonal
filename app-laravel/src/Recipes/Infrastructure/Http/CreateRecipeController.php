<?php

declare(strict_types=1);

namespace Recipes\Infrastructure\Http;

use Illuminate\Http\JsonResponse;
use Recipes\Application\CreateRecipe;

/**
 * CreateRecipeController: POST /api/recipes da de alta una receta.
 *
 * CONCEPTO: adaptador driving (de entrada)
 * Es la otra cara del EloquentRecipeRepository. Aquel es un adaptador
 * DRIVEN: el núcleo lo usa para salir (guardar). Este es DRIVING: el mundo
 * exterior lo usa para entrar y poner en marcha el núcleo. Su trabajo es
 * solo TRADUCIR entre HTTP y la aplicación:
 *
 *   petición HTTP ─► CreateRecipeRequest (forma) ─► CreateRecipeCommand
 *                 ─► CreateRecipe (caso de uso)   ─► RecipeId
 *                 ─► respuesta 201 {"id": ...}
 *
 * Lo que NO hace, a propósito:
 *   - validar reglas de negocio: lo hace el dominio, dentro del caso de uso;
 *   - capturar excepciones: un error de dominio sube y lo traduce a 422 el
 *     manejador global de bootstrap/app.php, igual para todos los
 *     controladores;
 *   - tocar Eloquent: guardar es cosa del puerto RecipeRepository.
 * Por eso cabe en tres líneas. En un Laravel clásico este controlador haría
 * Recipe::create($request->validated()) y la regla de negocio estaría
 * repartida entre la validación, el controlador y el modelo.
 *
 * CONCEPTO: inyección de dependencias en el controlador
 * Laravel construye el controlador con su contenedor: CreateRecipe llega por
 * el constructor, y a su vez trae el EloquentRecipeRepository gracias al
 * binding de RecipesServiceProvider. El controlador no sabe qué adaptador
 * hay detrás. La FormRequest se inyecta en __invoke y Laravel la valida antes
 * de ejecutarlo.
 *
 * CONCEPTO: controlador invocable (single action controller)
 * Igual que el caso de uso, hace una sola cosa, así que tiene un único método
 * __invoke y la ruta apunta a la clase: Route::post('/recipes', CreateRecipeController::class).
 *
 * Respuesta: 201 Created, el id en el cuerpo y la URL del nuevo recurso en
 * la cabecera Location, como indica HTTP para un POST que crea algo. La URL
 * es la de GET /api/recipes/{id} (HEXM-0011) y se genera con route() a
 * partir del nombre de la ruta, sin escribirla a mano.
 *
 * CAPA: Infraestructura, adaptador de entrada HTTP (Recipes\Infrastructure\Http).
 */
final readonly class CreateRecipeController
{
    public function __construct(private CreateRecipe $createRecipe) {}

    public function __invoke(CreateRecipeRequest $request): JsonResponse
    {
        $id = ($this->createRecipe)($request->toCommand());

        return new JsonResponse(
            ['id' => $id->value()],
            JsonResponse::HTTP_CREATED,
            ['Location' => route('recipes.show', ['id' => $id->value()])],
        );
    }
}
