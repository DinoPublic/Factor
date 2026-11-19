<?php

namespace App\Models;

use App\Enums\TipoLectura;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lectura extends Model
{
    protected $table = 'lecturas';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'contrato_id' => 'integer',
            'lectura_kwh' => 'integer',
            'tipo' => TipoLectura::class,
        ];
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(Contrato::class);
    }

    public function scopePorContratoYFecha(Builder $query, int $contratoId, string $fecha): void
    {
        $query->where('contrato_id', $contratoId)->where('fecha_lectura', $fecha);
    }

    public static function lecturaRealAnterior(int $contratoId, string $fecha): ?self
    {
        return self::where('contrato_id', $contratoId)
            ->where('tipo', TipoLectura::Real)
            ->where('fecha_lectura', '<', $fecha)
            ->orderByDesc('fecha_lectura')
            ->first();
    }

    public static function lecturaRealPosterior(int $contratoId, string $fecha): ?self
    {
        return self::where('contrato_id', $contratoId)
            ->where('tipo', TipoLectura::Real)
            ->where('fecha_lectura', '>', $fecha)
            ->orderBy('fecha_lectura')
            ->first();
    }

    public function esIgualA(int $lecturaKwh, TipoLectura $tipo): bool
    {
        return $this->lectura_kwh === $lecturaKwh && $this->tipo === $tipo;
    }
}
