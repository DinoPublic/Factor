<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contratos')) {
            Schema::create('contratos', function (Blueprint $table) {
                $table->id();
                $table->string('cups', 22)->index('ix_contratos_cups');
                $table->string('titular', 150);
                $table->string('nif', 15);
                $table->string('tarifa', 10);
                $table->string('estado', 10);
                $table->date('fecha_alta');
                $table->date('fecha_baja')->nullable();
                $table->unsignedTinyInteger('digitos_contador');
            });
        }

        if (! Schema::hasTable('lecturas')) {
            Schema::create('lecturas', function (Blueprint $table) {
                $table->id();
                $table->foreignId('contrato_id')->constrained('contratos');
                $table->date('fecha_lectura');
                $table->integer('lectura_kwh');
                $table->string('tipo', 10);
                $table->dateTime('fecha_recepcion', 0);
                $table->index(['contrato_id', 'fecha_lectura'], 'ix_lecturas_contrato_fecha');
            });
        }
    }

    public function down(): void
    {
        // Las tablas pertenecen a init.sql: no se eliminan al revertir.
    }
};
