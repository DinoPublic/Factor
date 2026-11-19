<?php

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class IndiceUnicoLecturasMigrationTest extends TestCase
{
    use RefreshDatabase;

    private Migration $migracion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migracion = require database_path('migrations/2026_11_19_000002_add_unique_contrato_fecha_to_lecturas_table.php');
        $this->migracion->down();

        DB::table('contratos')->insert([
            'id' => 3, 'cups' => 'ES0904488766344002MV', 'titular' => 'Titular 3', 'nif' => '00000000T', 'tarifa' => '2.0TD',
            'estado' => 'activo', 'fecha_alta' => '2023-07-01', 'fecha_baja' => null, 'digitos_contador' => 5,
        ]);
    }

    private function insertarLectura(int $id, int $kwh, string $recepcion): void
    {
        DB::table('lecturas')->insert([
            'id' => $id, 'contrato_id' => 3, 'fecha_lectura' => '2026-06-01', 'lectura_kwh' => $kwh,
            'tipo' => 'real', 'fecha_recepcion' => $recepcion,
        ]);
    }

    public function test_elimina_los_duplicados_exactos_conservando_el_primero_y_crea_el_indice(): void
    {
        $this->insertarLectura(121, 47755, '2026-06-02 06:18:20');
        $this->insertarLectura(137, 47755, '2026-06-03 06:55:20');

        $this->migracion->up();

        $this->assertSame([121], DB::table('lecturas')->pluck('id')->all());

        $this->expectException(UniqueConstraintViolationException::class);
        $this->insertarLectura(200, 47755, '2026-06-04 06:00:00');
    }

    public function test_no_borra_nada_si_hay_lecturas_distintas_para_la_misma_fecha(): void
    {
        $this->insertarLectura(121, 47755, '2026-06-02 06:18:20');
        $this->insertarLectura(137, 47800, '2026-06-03 06:55:20');

        try {
            $this->migracion->up();
            $this->fail('La migración debería haber fallado');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('revisarlas a mano', $e->getMessage());
        }

        $this->assertSame(2, DB::table('lecturas')->count());
    }
}
