<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Tabla de las importaciones de planes (contexto MealPlanning, HEXM-0032).
|
| CONCEPTO: el agregado como documento, otra vez (ADR 0013, ADR 0025)
| Una PlanImport se lee y se escribe siempre entera, y nada consulta sus
| partes por separado: como los planes, UNA FILA por importación, con el
| contenido de cada Excel como documento jsonb:
|   - nutritionist_plan: lo leído del Excel de la nutricionista (número,
|     pautas fijas, intercambios, reglas y semana 1), siempre presente;
|   - self_week: los días 8-14 del Excel de la semana 2, o NULL si aún no ha
|     llegado;
|   - nutrition_plan_id: el plan que se creó al confirmarla, o NULL.
|
| DECISIÓN: status como columna, aunque el dominio lo deduce
| En el dominio el estado se calcula (PlanImport::status()); aquí se guarda
| también, para que se pueda leer la tabla a simple vista y para que un CHECK
| impida filas incoherentes (una importación «confirmada» sin plan). El mapper
| lo escribe siempre a partir del dominio y no lo usa al leer.
|
| DECISIÓN: sin clave foránea hacia nutrition_plans
| La importación guarda el id del plan como referencia entre DOS agregados,
| igual que un plato guarda el id de una receta (ADR 0016): cada agregado
| es dueño de su ciclo de vida, y una FK ataría el borrado de uno al otro.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_imports', function (Blueprint $table) {
            // El id lo genera el dominio (UUID v7, ADR 0005).
            $table->uuid('id')->primary();
            $table->string('status', 20);
            $table->jsonb('nutritionist_plan');
            $table->jsonb('self_week')->nullable();
            $table->uuid('nutrition_plan_id')->nullable();

            // Operativas, no del dominio (como en nutrition_plans).
            $table->timestamps();
        });

        // CONCEPTO: CHECK que repite las invariantes del agregado
        // La red mínima de la base de datos (como en nutrition_plans): el
        // estado es uno de los tres, y es coherente con lo que hay en la
        // fila; y cada documento es del tipo JSON esperado.
        DB::statement(<<<'SQL'
            ALTER TABLE plan_imports ADD CONSTRAINT plan_imports_status_check CHECK (
                jsonb_typeof(nutritionist_plan) = 'object'
                AND (self_week IS NULL OR jsonb_typeof(self_week) = 'array')
                AND (
                    (status = 'awaiting_self_week' AND self_week IS NULL AND nutrition_plan_id IS NULL)
                    OR (status = 'ready_to_confirm' AND self_week IS NOT NULL AND nutrition_plan_id IS NULL)
                    OR (status = 'confirmed' AND self_week IS NOT NULL AND nutrition_plan_id IS NOT NULL)
                )
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_imports');
    }
};
