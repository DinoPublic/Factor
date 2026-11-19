<?php

namespace App\Exceptions;

use App\Enums\MotivoRechazo;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class LecturaRechazadaException extends RuntimeException
{
    public function __construct(
        public readonly MotivoRechazo $motivo,
        string $mensaje,
        public readonly array $detalles = [],
    ) {
        parent::__construct($mensaje);
    }

    public static function respuesta(MotivoRechazo $motivo, string $mensaje, array $detalles = []): JsonResponse
    {
        $error = ['codigo' => $motivo->value, 'mensaje' => $mensaje] + $detalles;

        return response()->json(['guardada' => false, 'error' => $error], $motivo->httpStatus(), [], JSON_UNESCAPED_UNICODE);
    }

    public function report(): void
    {
        Log::info('Lectura rechazada', ['motivo' => $this->motivo->value, 'mensaje' => $this->getMessage()]);
    }

    public function render(): JsonResponse
    {
        return self::respuesta($this->motivo, $this->getMessage(), $this->detalles);
    }
}
