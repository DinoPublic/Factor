<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Las fechas se manejan como strings 'Y-m-d' (sin cast) para que las comparaciones
 * sean idénticas en SQL Server y en SQLite (tests).
 */
class Contrato extends Model
{
    public const ESTADO_ACTIVO = 'activo';
    public const ESTADO_BAJA = 'baja';

    protected $table = 'contratos';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'digitos_contador' => 'integer',
        ];
    }

    public function lecturas(): HasMany
    {
        return $this->hasMany(Lectura::class);
    }

    public function scopePorCups(Builder $query, string $cups): void
    {
        $query->where('cups', $cups)->orderBy('fecha_alta');
    }

    public function estaDeBaja(): bool
    {
        return $this->estado === self::ESTADO_BAJA;
    }

    public function estaVigenteEn(string $fecha): bool
    {
        if ($fecha < $this->fecha_alta) {
            return false;
        }

        if ($this->fecha_baja === null) {
            return true;
        }

        return $fecha <= $this->fecha_baja;
    }

    public function lecturaMaxima(): int
    {
        return 10 ** $this->digitos_contador - 1;
    }

    public function esVueltaDeContador(int $lecturaAnterior, int $lecturaPosterior): bool
    {
        if ($lecturaPosterior >= $lecturaAnterior) {
            return false;
        }

        $consumoConVuelta = $this->lecturaMaxima() + 1 - $lecturaAnterior + $lecturaPosterior;

        return $consumoConVuelta < $lecturaAnterior - $lecturaPosterior;
    }
}
