<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('socios_padron', function (Blueprint $table) {
            $table->id();
            
            // Campos de identificación
            $table->string('dni')->nullable()->index();
            $table->string('sid')->nullable()->index();
            $table->string('apynom')->nullable();
            $table->string('barcode')->nullable()->unique();
            
            // Campos de estado
            $table->decimal('saldo', 12, 2)->nullable();
            $table->integer('semaforo')->nullable();
            $table->integer('ult_impago')->nullable();
            $table->boolean('acceso_full')->default(false);
            $table->boolean('hab_controles')->default(true);
            
            // Datos raw en JSON
            $table->json('hab_controles_raw')->nullable();
            $table->json('raw')->nullable();
            
            // Auditoría
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            
            // Índices compuestos
            $table->index(['dni', 'sid']);
            $table->index(['barcode', 'dni']);
            $table->index('updated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('socios_padron');
    }
};
