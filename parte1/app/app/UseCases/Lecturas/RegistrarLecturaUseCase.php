<?php

namespace App\UseCases\Lecturas;

use App\DTOs\LecturaEntranteData;
use App\DTOs\ResultadoRegistroLectura;
use App\Enums\MotivoRechazo;
use App\Exceptions\LecturaRechazadaException;
use App\Http\Resources\LecturaResource;
use App\Models\Contrato;
use App\Models\Lectura;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

class RegistrarLecturaUseCase
{
    public function ejecutar(LecturaEntranteData $datos): ResultadoRegistroLectura
    {
        $contrato = $this->contratoVigente($datos);

        $existente = Lectura::porContratoYFecha($contrato->id, $datos->fechaLectura)->first();
        if ($existente !== null) {
            return $this->comprobarLecturaExistente($existente->setRelation('contrato', $contrato), $datos);
        }

        $this->validarLectura($contrato, $datos);

        try {
            $lectura = Lectura::create([
                'contrato_id' => $contrato->id,
                'fecha_lectura' => $datos->fechaLectura,
                'lectura_kwh' => $datos->lecturaKwh,
                'tipo' => $datos->tipo,
                'fecha_recepcion' => now()->format('Y-m-d H:i:s'),
            ]);
        } catch (UniqueConstraintViolationException) {
            $existente = Lectura::porContratoYFecha($contrato->id, $datos->fechaLectura)->firstOrFail();

            return $this->comprobarLecturaExistente($existente->setRelation('contrato', $contrato), $datos);
        }

        return new ResultadoRegistroLectura($lectura->setRelation('contrato', $contrato), duplicada: false);
    }

    private function contratoVigente(LecturaEntranteData $datos): Contrato
    {
        $contratos = Contrato::porCups($datos->cups)->get();

        if ($contratos->isEmpty()) {
            throw new LecturaRechazadaException(
                MotivoRechazo::CupsDesconocido,
                "No existe ningún contrato con el CUPS {$datos->cups}.",
            );
        }

        foreach ($contratos as $contrato) {
            if ($contrato->estaVigenteEn($datos->fechaLectura)) {
                return $contrato;
            }
        }

        $vigencias = $contratos->map(fn (Contrato $c) => [
            'fecha_alta' => $c->fecha_alta,
            'fecha_baja' => $c->fecha_baja,
            'estado' => $c->estado,
        ])->values()->all();

        $activos = $contratos->where('estado', Contrato::ESTADO_ACTIVO);
        if ($activos->isEmpty()) {
            $ultimo = $contratos->last();

            throw new LecturaRechazadaException(
                MotivoRechazo::ContratoDeBaja,
                "El contrato del CUPS {$datos->cups} está de baja desde {$ultimo->fecha_baja} y no admite lecturas.",
                ['vigencias' => $vigencias],
            );
        }

        throw new LecturaRechazadaException(
            MotivoRechazo::FueraDeVigencia,
            "La fecha {$datos->fechaLectura} está fuera de la vigencia de los contratos del CUPS {$datos->cups}.",
            ['vigencias' => $vigencias],
        );
    }

    private function comprobarLecturaExistente(Lectura $existente, LecturaEntranteData $datos): ResultadoRegistroLectura
    {
        if ($existente->esIgualA($datos->lecturaKwh, $datos->tipo)) {
            return new ResultadoRegistroLectura($existente, duplicada: true);
        }

        throw new LecturaRechazadaException(
            MotivoRechazo::LecturaYaExiste,
            "Ya existe una lectura con otros datos para el CUPS {$datos->cups} en la fecha {$datos->fechaLectura}. No se sobrescribe.",
            ['lectura_existente' => LecturaResource::make($existente)->resolve()],
        );
    }

    private function validarLectura(Contrato $contrato, LecturaEntranteData $datos): void
    {
        if ($contrato->estaDeBaja()) {
            throw new LecturaRechazadaException(
                MotivoRechazo::ContratoDeBaja,
                "El contrato del CUPS {$datos->cups} en la fecha {$datos->fechaLectura} está de baja (desde {$contrato->fecha_baja}) y no admite lecturas.",
            );
        }

        if ($datos->lecturaKwh > $contrato->lecturaMaxima()) {
            throw new LecturaRechazadaException(
                MotivoRechazo::LecturaSuperaContador,
                "La lectura {$datos->lecturaKwh} no cabe en un contador de {$contrato->digitos_contador} dígitos (máximo {$contrato->lecturaMaxima()}).",
            );
        }

        $this->validarSecuencia($contrato, $datos);
    }

    private function validarSecuencia(Contrato $contrato, LecturaEntranteData $datos): void
    {
        $anterior = Lectura::lecturaRealAnterior($contrato->id, $datos->fechaLectura);
        if ($anterior !== null && $this->esRetrocesoInvalido($contrato, $anterior->lectura_kwh, $datos->lecturaKwh)) {
            throw new LecturaRechazadaException(
                MotivoRechazo::LecturaIncoherente,
                "La lectura {$datos->lecturaKwh} es inferior a la lectura real anterior ({$anterior->lectura_kwh} kWh el {$anterior->fecha_lectura}).",
                ['lectura_anterior' => LecturaResource::make($anterior->setRelation('contrato', $contrato))->resolve()],
            );
        }

        $posterior = Lectura::lecturaRealPosterior($contrato->id, $datos->fechaLectura);
        if ($posterior !== null && $this->esRetrocesoInvalido($contrato, $datos->lecturaKwh, $posterior->lectura_kwh)) {
            throw new LecturaRechazadaException(
                MotivoRechazo::LecturaIncoherente,
                "La lectura {$datos->lecturaKwh} es superior a la lectura real posterior ({$posterior->lectura_kwh} kWh el {$posterior->fecha_lectura}).",
                ['lectura_posterior' => LecturaResource::make($posterior->setRelation('contrato', $contrato))->resolve()],
            );
        }

        if ($anterior !== null && $contrato->esVueltaDeContador($anterior->lectura_kwh, $datos->lecturaKwh)) {
            Log::info('Vuelta de contador detectada', [
                'cups' => $datos->cups,
                'anterior' => $anterior->lectura_kwh,
                'nueva' => $datos->lecturaKwh,
            ]);
        }
    }

    private function esRetrocesoInvalido(Contrato $contrato, int $antes, int $despues): bool
    {
        return $despues < $antes && ! $contrato->esVueltaDeContador($antes, $despues);
    }
}
