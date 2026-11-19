<?php

namespace Tests\Unit;

use App\Models\Contrato;
use PHPUnit\Framework\TestCase;

class ContratoTest extends TestCase
{
    public function test_detecta_la_vuelta_a_cero_del_contador(): void
    {
        $contador5 = new Contrato(['digitos_contador' => 5]);
        $contador6 = new Contrato(['digitos_contador' => 6]);

        $this->assertFalse($contador5->esVueltaDeContador(50000, 50300));   // avance normal
        $this->assertTrue($contador5->esVueltaDeContador(99812, 150));      // vuelta con 5 dígitos
        $this->assertFalse($contador5->esVueltaDeContador(50000, 49000));   // retroceso pequeño
        $this->assertTrue($contador6->esVueltaDeContador(999500, 200));     // vuelta con 6 dígitos
        $this->assertFalse($contador6->esVueltaDeContador(127181, 99000));  // retroceso con 6 dígitos
    }

    public function test_la_vigencia_incluye_los_dias_de_alta_y_de_baja(): void
    {
        $contrato = new Contrato(['fecha_alta' => '2023-01-01', 'fecha_baja' => '2026-07-15']);

        $this->assertFalse($contrato->estaVigenteEn('2022-12-31'));
        $this->assertTrue($contrato->estaVigenteEn('2023-01-01'));
        $this->assertTrue($contrato->estaVigenteEn('2026-07-15'));
        $this->assertFalse($contrato->estaVigenteEn('2026-07-16'));
    }
}
