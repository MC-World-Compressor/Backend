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
        Schema::create('servidores', function (Blueprint $table) {
            $table->id();
            $table->string('ruta')->nullable();
            $table->string('estado')->default('pendiente');
            $table->decimal('tamano_inicio', 8, 2)->nullable();
            $table->decimal('tamano_final', 8, 2)->nullable();
            $table->timestamp('fecha_expiracion')->nullable();
            $table->timestamp('fecha_creacion')->useCurrent();
            $table->string('ip')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('servidores');
    }
};
