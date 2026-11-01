<?php

declare(strict_types=1);

namespace Recipes\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Recipes\Domain\RecipeRepository;
use Recipes\Infrastructure\Persistence\Eloquent\EloquentRecipeRepository;

/**
 * RecipesServiceProvider: conecta los puertos del recetario con sus adaptadores.
 *
 * CONCEPTO: composition root (raíz de composición)
 * El núcleo (Domain y Application) solo habla con INTERFACES: CreateRecipe
 * pide "un RecipeRepository" en su constructor y no sabe cuál le llegará.
 * Alguien tiene que decidir, en un único sitio, qué adaptador concreto se
 * enchufa a cada puerto. Ese sitio es la raíz de composición, y en Laravel es
 * un service provider. Es el único lugar del recetario donde aparecen juntos
 * el puerto y su adaptador.
 *
 * CONCEPTO: binding en el contenedor de servicios de Laravel
 * El contenedor (service container) es el objeto que construye las clases
 * resolviendo sus dependencias. Sin este binding, al pedir CreateRecipe
 * fallaría: RecipeRepository es una interfaz y no se puede instanciar.
 * Con él, el contenedor resuelve todo el árbol solo (autowiring):
 *
 *   app(CreateRecipe::class)
 *     └─ necesita RecipeRepository → binding → new EloquentRecipeRepository
 *          └─ necesita RecipeMapper → tiene valor por defecto → new RecipeMapper
 *
 * CreateRecipe no necesita binding: es una clase concreta y el contenedor
 * lee su constructor por reflexión.
 *
 * Cambiar de adaptador (por ejemplo, el de memoria en un entorno de demo) es
 * cambiar UNA línea aquí, sin tocar el caso de uso. Los tests unitarios ni
 * siquiera pasan por aquí: construyen CreateRecipe a mano con el adaptador en
 * memoria (inyección de dependencias sin contenedor).
 *
 * DECISIÓN: bind y no singleton
 * - bind: una instancia nueva cada vez que se pide el puerto.
 * - singleton: una sola instancia durante toda la vida del proceso.
 * El repositorio no guarda estado (es readonly), así que los dos servirían.
 * bind es la opción segura por defecto: si un día el adaptador tuviera estado
 * (una caché, una identidad de sesión), un singleton lo compartiría entre
 * peticiones en servidores que reutilizan el proceso, como Laravel Octane.
 *
 * DECISIÓN: un provider por bounded context, no AppServiceProvider
 * Cada contexto registra sus propios adaptadores dentro de su carpeta. Así
 * Recipes y MealPlanning no se mezclan en un fichero común de app/, y el
 * contexto se puede leer (o extraer) entero desde src/Recipes.
 *
 * Alternativa equivalente: la propiedad `public array $bindings = [...]` del
 * ServiceProvider hace lo mismo con menos código. Se usa register() porque es
 * más explícito y deja sitio a bindings que necesiten configuración.
 *
 * CAPA: Infraestructura (Recipes\Infrastructure). Puede usar Laravel: es
 * precisamente la capa que conecta el framework con el núcleo.
 * Se registra en bootstrap/providers.php.
 */
final class RecipesServiceProvider extends ServiceProvider
{
    /**
     * register() solo debe registrar bindings: en este momento otros
     * providers aún no se han cargado, así que no se resuelve nada aquí
     * (para eso está boot()).
     */
    public function register(): void
    {
        // Puerto de salida (Domain) → adaptador de salida (Infrastructure).
        $this->app->bind(RecipeRepository::class, EloquentRecipeRepository::class);
    }

    /**
     * boot() se ejecuta cuando todos los providers ya están registrados: es
     * el sitio para configurar piezas del framework, como las vistas.
     *
     * CONCEPTO: namespace de vistas (loadViewsFrom)
     * Las vistas del recetario viven dentro del contexto
     * (Recipes/Infrastructure/Http/views), junto a los controladores que las
     * usan, en vez de en resources/views. Con el namespace "recipes" se
     * llaman como view('recipes::show'). Así todo el recetario se lee desde
     * src/Recipes, igual que sus controladores y su repositorio (decisión de
     * HEXM-0016). Es el mismo mecanismo que usan los paquetes de Laravel
     * para traer sus propias vistas.
     */
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/Http/views', 'recipes');
    }
}
