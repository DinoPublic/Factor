<?php

namespace Tests\Feature;

use App\Models\Contrato;
use App\Models\Lectura;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RegistrarLecturaTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-de-pruebas';
    private const CUPS_ACTIVO = 'ES0904998698797309NB';       // contrato 1
    private const CUPS_BAJA = 'ES0904229486628360ZC';         // contrato 5, baja 2026-08-31
    private const CUPS_CAMBIO_TITULAR = 'ES0904688281126584QG'; // contratos 12 (baja 2026-07-15) y 24 (alta 2026-07-16)
    private const CUPS_CASI_VUELTA = 'ES0904145244106460TD';  // contrato 8, contador en 99812
    private const CUPS_ESTIMADA = 'ES0904856517883655LE';     // contrato 15, última lectura estimada
    private const CUPS_ALTA_RECIENTE = 'ES0904386713006681DW'; // contrato 25, alta 2026-11-01
    private const CUPS_6_DIGITOS = 'ES0904858176252807GJ';    // contrato 19

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-19 10:00:00');
        config(['lecturas.api_token' => self::TOKEN]);

        $contratos = [
            [1, self::CUPS_ACTIVO, 'activo', '2024-02-01', null, 5],
            [5, self::CUPS_BAJA, 'baja', '2021-02-01', '2026-08-31', 5],
            [8, self::CUPS_CASI_VUELTA, 'activo', '2023-06-01', null, 5],
            [12, self::CUPS_CAMBIO_TITULAR, 'baja', '2023-01-01', '2026-07-15', 5],
            [15, self::CUPS_ESTIMADA, 'activo', '2024-07-01', null, 5],
            [19, self::CUPS_6_DIGITOS, 'activo', '2023-01-01', null, 6],
            [24, self::CUPS_CAMBIO_TITULAR, 'activo', '2026-07-16', null, 5],
            [25, self::CUPS_ALTA_RECIENTE, 'activo', '2026-11-01', null, 5],
        ];
        foreach ($contratos as [$id, $cups, $estado, $alta, $baja, $digitos]) {
            DB::table('contratos')->insert([
                'id' => $id, 'cups' => $cups, 'titular' => "Titular {$id}", 'nif' => '00000000T', 'tarifa' => '2.0TD',
                'estado' => $estado, 'fecha_alta' => $alta, 'fecha_baja' => $baja, 'digitos_contador' => $digitos,
            ]);
        }

        $lecturas = [
            [1, '2026-10-01', 10849, 'real'],
            [5, '2026-08-31', 12061, 'real'],
            [8, '2026-10-01', 99812, 'real'],
            [12, '2026-07-15', 25317, 'real'],
            [15, '2026-09-01', 9219, 'real'],
            [15, '2026-10-01', 9684, 'estimada'],
            [19, '2026-10-01', 127181, 'real'],
            [24, '2026-10-01', 26176, 'real'],
        ];
        foreach ($lecturas as [$contrato, $fecha, $kwh, $tipo]) {
            DB::table('lecturas')->insert([
                'contrato_id' => $contrato, 'fecha_lectura' => $fecha, 'lectura_kwh' => $kwh,
                'tipo' => $tipo, 'fecha_recepcion' => '2026-10-02 06:00:00',
            ]);
        }
    }

    private function enviar(array $datos, ?string $token = self::TOKEN): TestResponse
    {
        $cabeceras = $token === null ? [] : ['Authorization' => "Bearer {$token}"];

        return $this->postJson('/api/v1/lecturas', $datos, $cabeceras);
    }

    private function lectura(string $cups, string $fecha, int $kwh, string $tipo = 'real'): array
    {
        return ['cups' => $cups, 'fecha_lectura' => $fecha, 'lectura_kwh' => $kwh, 'tipo' => $tipo];
    }

    private function assertRechazada(TestResponse $respuesta, int $status, string $codigo): void
    {
        $respuesta->assertStatus($status)
            ->assertJsonPath('guardada', false)
            ->assertJsonPath('error.codigo', $codigo)
            ->assertJsonStructure(['error' => ['mensaje']]);
    }

    public function test_guarda_una_lectura_valida(): void
    {
        $this->enviar($this->lectura(self::CUPS_ACTIVO, '2026-11-01', 11210))
            ->assertCreated()
            ->assertJson([
                'guardada' => true,
                'duplicada' => false,
                'lectura' => [
                    'cups' => self::CUPS_ACTIVO,
                    'fecha_lectura' => '2026-11-01',
                    'lectura_kwh' => 11210,
                    'tipo' => 'real',
                ],
            ]);

        $this->assertDatabaseHas('lecturas', ['contrato_id' => 1, 'fecha_lectura' => '2026-11-01', 'lectura_kwh' => 11210]);
    }

    public function test_un_reenvio_identico_no_duplica_y_devuelve_la_lectura_original(): void
    {
        $datos = $this->lectura(self::CUPS_ACTIVO, '2026-11-01', 11210);

        $primera = $this->enviar($datos)->assertCreated();
        $this->travel(5)->minutes();
        $segunda = $this->enviar($datos)->assertOk()->assertJsonPath('duplicada', true);

        $this->assertSame($primera->json('lectura'), $segunda->json('lectura'));
        $this->assertSame(1, Lectura::where('contrato_id', 1)->where('fecha_lectura', '2026-11-01')->count());
    }

    public function test_un_reenvio_de_una_lectura_ya_guardada_de_un_contrato_de_baja_responde_guardada(): void
    {
        $this->enviar($this->lectura(self::CUPS_BAJA, '2026-08-31', 12061))
            ->assertOk()
            ->assertJsonPath('duplicada', true);
    }

    public function test_misma_fecha_con_datos_distintos_es_un_conflicto_y_no_sobrescribe(): void
    {
        $respuesta = $this->enviar($this->lectura(self::CUPS_ACTIVO, '2026-10-01', 10900));
        $this->assertRechazada($respuesta, 409, 'LECTURA_YA_EXISTE');
        $respuesta->assertJsonPath('error.lectura_existente.lectura_kwh', 10849);

        $this->assertDatabaseHas('lecturas', ['contrato_id' => 1, 'fecha_lectura' => '2026-10-01', 'lectura_kwh' => 10849]);
    }

    public function test_rechaza_lecturas_de_un_contrato_de_baja(): void
    {
        $this->assertRechazada($this->enviar($this->lectura(self::CUPS_BAJA, '2026-10-01', 12500)), 422, 'CONTRATO_DE_BAJA');
        $this->assertRechazada($this->enviar($this->lectura(self::CUPS_BAJA, '2026-08-15', 12000)), 422, 'CONTRATO_DE_BAJA');

        $this->assertDatabaseCount('lecturas', 8);
    }

    public function test_rechaza_lecturas_anteriores_al_alta_del_contrato(): void
    {
        $respuesta = $this->enviar($this->lectura(self::CUPS_ALTA_RECIENTE, '2026-10-15', 100));

        $this->assertRechazada($respuesta, 422, 'FUERA_DE_VIGENCIA');
        $respuesta->assertJsonPath('error.vigencias.0.fecha_alta', '2026-11-01');
    }

    public function test_acepta_la_lectura_del_dia_de_alta(): void
    {
        $this->enviar($this->lectura(self::CUPS_ALTA_RECIENTE, '2026-11-01', 100))->assertCreated();
    }

    public function test_con_cambio_de_titular_asigna_la_lectura_al_contrato_vigente_en_su_fecha(): void
    {
        $this->assertRechazada(
            $this->enviar($this->lectura(self::CUPS_CAMBIO_TITULAR, '2026-07-01', 25100)),
            422,
            'CONTRATO_DE_BAJA',
        );

        $this->enviar($this->lectura(self::CUPS_CAMBIO_TITULAR, '2026-11-01', 26500))->assertCreated();
        $this->assertDatabaseHas('lecturas', ['contrato_id' => 24, 'fecha_lectura' => '2026-11-01']);
    }

    public function test_rechaza_un_cups_que_no_tenemos(): void
    {
        $this->assertRechazada($this->enviar($this->lectura('ES0904000000000000QE', '2026-11-01', 1)), 422, 'CUPS_DESCONOCIDO');
    }

    public function test_rechaza_datos_mal_formados_indicando_cada_campo(): void
    {
        $respuesta = $this->enviar([
            'cups' => 'ES0904998698797309XX',
            'fecha_lectura' => '01/11/2026',
            'lectura_kwh' => -5,
            'tipo' => 'inventada',
        ]);

        $this->assertRechazada($respuesta, 422, 'DATOS_NO_VALIDOS');
        $respuesta->assertJsonStructure(['error' => ['campos' => ['cups', 'fecha_lectura', 'lectura_kwh', 'tipo']]]);
    }

    public function test_rechaza_fechas_futuras(): void
    {
        $respuesta = $this->enviar($this->lectura(self::CUPS_ACTIVO, '2026-11-20', 11300));

        $this->assertRechazada($respuesta, 422, 'DATOS_NO_VALIDOS');
        $respuesta->assertJsonStructure(['error' => ['campos' => ['fecha_lectura']]]);
    }

    public function test_normaliza_el_cups_a_mayusculas(): void
    {
        $this->enviar($this->lectura(' es0904998698797309nb ', '2026-11-01', 11210))
            ->assertCreated()
            ->assertJsonPath('lectura.cups', self::CUPS_ACTIVO);
    }

    public function test_acepta_el_cups_de_22_caracteres_y_lo_asocia_al_de_20(): void
    {
        $this->enviar($this->lectura(self::CUPS_ACTIVO.'0F', '2026-11-01', 11210))
            ->assertCreated()
            ->assertJsonPath('lectura.cups', self::CUPS_ACTIVO);
    }

    public function test_rechaza_lecturas_que_no_caben_en_el_contador(): void
    {
        $this->assertRechazada($this->enviar($this->lectura(self::CUPS_ACTIVO, '2026-11-01', 100000)), 422, 'LECTURA_SUPERA_CONTADOR');
        $this->enviar($this->lectura(self::CUPS_6_DIGITOS, '2026-11-01', 130000))->assertCreated();
    }

    public function test_rechaza_una_lectura_inferior_a_la_real_anterior(): void
    {
        $respuesta = $this->enviar($this->lectura(self::CUPS_ACTIVO, '2026-11-01', 10000));

        $this->assertRechazada($respuesta, 422, 'LECTURA_INCOHERENTE');
        $respuesta->assertJsonPath('error.lectura_anterior.lectura_kwh', 10849);
    }

    public function test_rechaza_una_lectura_atrasada_superior_a_la_real_posterior(): void
    {
        $this->assertRechazada($this->enviar($this->lectura(self::CUPS_ACTIVO, '2026-09-01', 11000)), 422, 'LECTURA_INCOHERENTE');
        $this->enviar($this->lectura(self::CUPS_ACTIVO, '2026-09-01', 10500))->assertCreated();
    }

    public function test_acepta_la_vuelta_a_cero_del_contador(): void
    {
        $this->enviar($this->lectura(self::CUPS_CASI_VUELTA, '2026-11-01', 150))->assertCreated();
    }

    public function test_una_real_inferior_a_una_estimada_regulariza_y_se_acepta(): void
    {
        $this->enviar($this->lectura(self::CUPS_ESTIMADA, '2026-11-01', 9600))->assertCreated();
    }

    public function test_una_lectura_no_puede_quedar_por_debajo_de_la_ultima_real_aunque_haya_una_estimada_en_medio(): void
    {
        $respuesta = $this->enviar($this->lectura(self::CUPS_ESTIMADA, '2026-11-01', 9000));

        $this->assertRechazada($respuesta, 422, 'LECTURA_INCOHERENTE');
        $respuesta->assertJsonPath('error.lectura_anterior.fecha_lectura', '2026-09-01');
    }

    public function test_exige_token_de_la_distribuidora(): void
    {
        $datos = $this->lectura(self::CUPS_ACTIVO, '2026-11-01', 11210);

        $this->assertRechazada($this->enviar($datos, token: null), 401, 'NO_AUTORIZADO');
        $this->assertRechazada($this->enviar($datos, token: 'otro'), 401, 'NO_AUTORIZADO');
        $this->assertDatabaseCount('lecturas', 8);
    }
}
