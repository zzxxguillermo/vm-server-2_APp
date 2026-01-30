<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('professor_socio', function (Blueprint $table) {
            $table->id();

            $table->foreignId('professor_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('socio_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('assigned_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->unique(['professor_id', 'socio_id']);
            $table->index('professor_id');
            $table->index('socio_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('professor_socio');
    }
};
