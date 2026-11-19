<?php

namespace App\Http\Requests;

use App\DTOs\LecturaEntranteData;
use App\Enums\MotivoRechazo;
use App\Enums\TipoLectura;
use App\Exceptions\LecturaRechazadaException;
use App\Rules\CupsValido;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class RegistrarLecturaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('cups'))) {
            $this->merge(['cups' => strtoupper(trim($this->input('cups')))]);
        }
    }

    public function rules(): array
    {
        return [
            'cups' => ['required', 'string', new CupsValido],
            'fecha_lectura' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'lectura_kwh' => ['required', 'integer', 'min:0'],
            'tipo' => ['required', Rule::enum(TipoLectura::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'El campo :attribute es obligatorio.',
            'string' => 'El campo :attribute debe ser un texto.',
            'fecha_lectura.date_format' => 'La fecha de lectura debe tener formato AAAA-MM-DD.',
            'fecha_lectura.before_or_equal' => 'La fecha de lectura no puede ser futura.',
            'lectura_kwh.integer' => 'La lectura debe ser un número entero de kWh.',
            'lectura_kwh.min' => 'La lectura no puede ser negativa.',
            'tipo.enum' => 'El tipo debe ser "real" o "estimada".',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $respuesta = LecturaRechazadaException::respuesta(
            MotivoRechazo::DatosNoValidos,
            'La petición contiene datos no válidos. La lectura no se ha guardado.',
            ['campos' => $validator->errors()->toArray()],
        );

        throw new HttpResponseException($respuesta);
    }

    public function toData(): LecturaEntranteData
    {
        return new LecturaEntranteData(
            cups: substr($this->validated('cups'), 0, 20),
            fechaLectura: $this->validated('fecha_lectura'),
            lecturaKwh: (int) $this->validated('lectura_kwh'),
            tipo: TipoLectura::from($this->validated('tipo')),
        );
    }
}
