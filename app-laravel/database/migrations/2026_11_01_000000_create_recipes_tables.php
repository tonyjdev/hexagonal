<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Tablas del recetario (contexto Recipes).
|
| CONCEPTO: el esquema es un detalle del adaptador, no el modelo de dominio
| Estas tablas existen para que EloquentRecipeRepository pueda guardar y
| recuperar el agregado Recipe. El dominio no las conoce: si mañana se
| guardaran las recetas en un documento JSON, Recipe no cambiaría. Por eso
| el esquema se diseña pensando en "cómo reconstruyo el agregado entero",
| no al revés (en un Laravel clásico se diseñan las tablas y el modelo de
| Eloquent ES el modelo de negocio).
|
| Una receta es UN agregado repartido en TRES tablas:
|
|   recipes              la raíz (identidad, nombre, raciones)
|   recipe_ingredients   sus ingredientes (value objects), en orden
|   recipe_steps         sus pasos (value objects), en orden
|
| Ver docs/adr/0006-persistencia-del-agregado-recipe.md.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $table) {
            // El id lo genera el DOMINIO (RecipeId, UUID v7, ADR 0005): aquí
            // no hay autoincremental, solo una columna de tipo uuid nativo de
            // PostgreSQL (16 bytes, no un varchar de 36).
            $table->uuid('id')->primary();
            $table->string('name');
            $table->unsignedSmallInteger('servings');
            // Las marcas de tiempo no son del dominio (Recipe no tiene fecha
            // de creación): son información operativa que Eloquent rellena
            // sola y que viene bien para depurar.
            $table->timestamps();
        });

        Schema::create('recipe_ingredients', function (Blueprint $table) {
            // CONCEPTO: clave primaria compuesta para un value object
            // Un Ingredient no tiene identidad (no hay IngredientId), así que
            // la fila tampoco necesita un id propio. Lo que la identifica es
            // "el ingrediente número N de la receta X": (recipe_id, position).
            // La posición conserva además el orden en que se escribieron.
            $table->foreignUuid('recipe_id')->constrained('recipes')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('name');

            // La Quantity como fracción exacta (ver Quantity): ½ se guarda
            // como 1 / 2, nunca como 0.5. Las tres columnas son nulas cuando
            // el ingrediente es "al gusto".
            $table->unsignedInteger('numerator')->nullable();
            $table->unsignedInteger('denominator')->nullable();
            // El valor del enum Unit ('gram', 'jar'...). Se guarda como texto
            // y no como enum de PostgreSQL: añadir una unidad al dominio no
            // debería exigir un ALTER TYPE.
            $table->string('unit', 20)->nullable();

            $table->primary(['recipe_id', 'position']);
        });

        // CONCEPTO: restricciones en la base de datos como segunda red
        // El dominio ya garantiza estas reglas (no existe una Quantity con
        // denominador cero ni un ingrediente con "media cantidad"). Repetirlas
        // aquí protege los datos de lo que NO pasa por el dominio: un UPDATE a
        // mano, un script de importación... El Schema Builder de Laravel no
        // tiene CHECK, así que se escribe en SQL (solo usamos PostgreSQL, ADR
        // 0002).
        DB::statement(<<<'SQL'
            ALTER TABLE recipe_ingredients ADD CONSTRAINT recipe_ingredients_quantity_check CHECK (
                (numerator IS NULL AND denominator IS NULL AND unit IS NULL)
                OR (numerator > 0 AND denominator > 0 AND unit IS NOT NULL)
            )
            SQL);

        Schema::create('recipe_steps', function (Blueprint $table) {
            // Igual que los ingredientes: un Step es un value object y su
            // número de paso ES su posición en la receta.
            $table->foreignUuid('recipe_id')->constrained('recipes')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->text('text');

            $table->primary(['recipe_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_steps');
        Schema::dropIfExists('recipe_ingredients');
        Schema::dropIfExists('recipes');
    }
};
