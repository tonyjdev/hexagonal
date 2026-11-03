<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Tabla de los planes nutricionales (contexto MealPlanning).
|
| CONCEPTO: el agregado como documento (HEXM-0020, ADR 0013)
| Un NutritionPlan tiene cuatro niveles (plan → semanas → días → platos →
| ingredientes) más las pautas fijas y la tabla de intercambios. Repartirlo
| en tablas, como el recetario (ADR 0006), daría unas nueve tablas. Pero el
| agregado SIEMPRE se lee y se escribe entero, y nada consulta sus partes
| por separado: las dos reglas del repositorio (la vigencia y "un inicio por
| día", ADR 0011) solo miran starts_on.
|
| Por eso se guarda UNA FILA POR PLAN:
|   - columnas normales para lo que se consulta o se indexa: id, number y
|     starts_on (con índice único);
|   - columnas jsonb para el contenido: las pautas fijas, la tabla de
|     intercambios y las dos semanas, como documentos JSON.
|
| CONCEPTO: jsonb frente a json en PostgreSQL
| json guarda el texto tal cual; jsonb lo guarda ya analizado, en binario:
| es algo más lento de escribir, pero más rápido de leer, se puede indexar
| y admite operadores (->, @>, jsonb_typeof...). Es la opción recomendada
| salvo que haga falta conservar el texto exacto (espacios, orden de claves).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nutrition_plans', function (Blueprint $table) {
            // El id lo genera el dominio (UUID v7, ADR 0005).
            $table->uuid('id')->primary();
            $table->unsignedInteger('number');

            // CONCEPTO: una regla de negocio garantizada por la base de datos
            // "Dos planes distintos no pueden empezar el mismo día" (regla 5,
            // ADR 0011). El índice único hace que PostgreSQL la cumpla
            // SIEMPRE, también con dos guardados a la vez (concurrencia), algo
            // que una comprobación previa en PHP no garantiza. El repositorio
            // traduce su violación a DuplicatedPlanStart.
            //
            // date (y no timestamp): un plan empieza un DÍA del calendario,
            // sin hora ni zona horaria. Así la comparación de inForceOn() es
            // de fechas, como en el dominio.
            $table->date('starts_on')->unique();

            $table->jsonb('fixed_meals');
            $table->jsonb('exchange_table');
            $table->jsonb('weeks');

            // Operativas, no del dominio (como en recipes).
            $table->timestamps();
        });

        // CONCEPTO: CHECK sobre un documento JSON
        // PostgreSQL no conoce la forma de un plan: el contenido lo valida el
        // dominio al cargarlo (NutritionPlanMapper). Pero una red mínima en la
        // base de datos detecta los errores más gordos de quien escriba a
        // mano (un UPDATE, una migración): que cada documento sea del tipo
        // JSON esperado y que haya exactamente dos semanas. Laravel no tiene
        // método para CHECK, así que se escribe en SQL (solo PostgreSQL,
        // ADR 0002).
        DB::statement(<<<'SQL'
            ALTER TABLE nutrition_plans ADD CONSTRAINT nutrition_plans_documents_check CHECK (
                jsonb_typeof(fixed_meals) = 'array'
                AND jsonb_typeof(exchange_table) = 'object'
                AND jsonb_typeof(weeks) = 'array'
                AND jsonb_array_length(weeks) = 2
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('nutrition_plans');
    }
};
