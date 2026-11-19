<?php

namespace App\Enums;

enum MotivoRechazo: string
{
    case NoAutorizado = 'NO_AUTORIZADO';
    case DatosNoValidos = 'DATOS_NO_VALIDOS';
    case CupsDesconocido = 'CUPS_DESCONOCIDO';
    case ContratoDeBaja = 'CONTRATO_DE_BAJA';
    case FueraDeVigencia = 'FUERA_DE_VIGENCIA';
    case LecturaSuperaContador = 'LECTURA_SUPERA_CONTADOR';
    case LecturaIncoherente = 'LECTURA_INCOHERENTE';
    case LecturaYaExiste = 'LECTURA_YA_EXISTE';

    public function httpStatus(): int
    {
        return match ($this) {
            self::NoAutorizado => 401,
            self::LecturaYaExiste => 409,
            default => 422,
        };
    }
}
