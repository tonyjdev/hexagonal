<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Número de plan único (regla 22, HEXM-0023).
|
| CONCEPTO: el esquema evoluciona con migraciones NUEVAS
| La tabla nutrition_plans se creó en HEXM-0020 y esa migración ya está en
| develop (y aplicada en cualquier base de datos que la haya ejecutado).
| Editarla no serviría de nada en esas bases de datos: Laravel no vuelve a
| ejecutar una migración que ya aparece en la tabla migrations. Por eso cada
| cambio del esquema es una migración nueva, con su up() y su down(): el
| historial de migraciones cuenta cómo ha evolucionado la base de datos,
| igual que git cuenta cómo ha evolucionado el código.
|
| Si ya hubiera dos planes con el mismo número, esta migración fallaría al
| crear el índice: habría que decidir antes qué hacer con ellos. Es lo
| correcto: una regla nueva no puede aceptarse en silencio sobre datos que
| la incumplen.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nutrition_plans', function (Blueprint $table) {
            // Nombre por convención de Laravel: nutrition_plans_number_unique.
            // El repositorio no depende del nombre: tras la violación, busca
            // qué plan ocupa ese día o ese número.
            $table->unique('number');
        });
    }

    public function down(): void
    {
        Schema::table('nutrition_plans', function (Blueprint $table) {
            $table->dropUnique(['number']);
        });
    }
};
