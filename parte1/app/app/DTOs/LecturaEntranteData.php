<?php

namespace App\DTOs;

use App\Enums\TipoLectura;

final readonly class LecturaEntranteData
{
    public function __construct(
        public string $cups,
        public string $fechaLectura,
        public int $lecturaKwh,
        public TipoLectura $tipo,
    ) {}
}
