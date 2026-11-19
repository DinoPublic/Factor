<?php

namespace App\DTOs;

use App\Models\Lectura;

final readonly class ResultadoRegistroLectura
{
    public function __construct(
        public Lectura $lectura,
        public bool $duplicada,
    ) {}
}
