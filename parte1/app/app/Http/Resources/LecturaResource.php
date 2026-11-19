<?php

namespace App\Http\Resources;

use App\Models\Lectura;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** @mixin Lectura */
class LecturaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cups' => $this->contrato->cups,
            'fecha_lectura' => $this->fecha_lectura,
            'lectura_kwh' => $this->lectura_kwh,
            'tipo' => $this->tipo->value,
            'fecha_recepcion' => Carbon::parse($this->fecha_recepcion)->toIso8601String(),
        ];
    }
}
