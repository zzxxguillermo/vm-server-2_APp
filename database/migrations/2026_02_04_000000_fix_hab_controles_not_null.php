<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('socios_padron', function (Blueprint $table) {
            // Cambiar hab_controles de boolean nullable a integer NOT NULL con default 0
            // Primero: setear todos los NULL a 0
            DB::statement('UPDATE socios_padron SET hab_controles = 0 WHERE hab_controles IS NULL');
            
            // Segundo: cambiar el tipo y agregar NOT NULL
            // En MySQL: cambiar de BOOLEAN a INT NOT NULL DEFAULT 0
            DB::statement('ALTER TABLE socios_padron MODIFY hab_controles INT NOT NULL DEFAULT 0');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('socios_padron', function (Blueprint $table) {
            // Revertir: volver a boolean nullable
            DB::statement('ALTER TABLE socios_padron MODIFY hab_controles BOOLEAN DEFAULT 1 NULL');
        });
    }
};
