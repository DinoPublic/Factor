<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicadasExactas = DB::table('lecturas as l')
            ->whereExists(fn ($q) => $q->from('lecturas as o')
                ->whereColumn('o.contrato_id', 'l.contrato_id')
                ->whereColumn('o.fecha_lectura', 'l.fecha_lectura')
                ->whereColumn('o.lectura_kwh', 'l.lectura_kwh')
                ->whereColumn('o.tipo', 'l.tipo')
                ->whereColumn('o.id', '<', 'l.id'))
            ->pluck('l.id');

        if ($duplicadasExactas->isNotEmpty()) {
            Log::warning('Eliminadas lecturas duplicadas antes de crear el índice único', ['ids' => $duplicadasExactas->all()]);
            DB::table('lecturas')->whereIn('id', $duplicadasExactas)->delete();
        }

        $conflictos = DB::table('lecturas')
            ->select('contrato_id', 'fecha_lectura')
            ->groupBy('contrato_id', 'fecha_lectura')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($conflictos->isNotEmpty()) {
            throw new RuntimeException(
                'Hay lecturas distintas para el mismo contrato y fecha; revisarlas a mano antes de crear el índice único: '
                .$conflictos->toJson()
            );
        }

        Schema::table('lecturas', function (Blueprint $table) {
            $table->unique(['contrato_id', 'fecha_lectura'], 'ux_lecturas_contrato_fecha');
        });
    }

    public function down(): void
    {
        Schema::table('lecturas', function (Blueprint $table) {
            $table->dropUnique('ux_lecturas_contrato_fecha');
        });
    }
};
