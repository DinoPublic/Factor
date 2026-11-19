<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class CupsValido implements ValidationRule
{
    private const LETRAS = 'TRWAGMYFPDXBNJZSQVHLCKE';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^ES(\d{16})([A-Z]{2})(\d[A-Z])?$/', $value, $m)) {
            $fail('El CUPS no tiene un formato válido (ES + 16 dígitos + 2 letras de control).');

            return;
        }

        $resto = (int) $m[1] % 529;
        $control = self::LETRAS[intdiv($resto, 23)].self::LETRAS[$resto % 23];

        if ($control !== $m[2]) {
            $fail("Las letras de control del CUPS no son correctas (se esperaba {$control}).");
        }
    }
}
