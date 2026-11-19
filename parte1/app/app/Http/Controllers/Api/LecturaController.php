<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegistrarLecturaRequest;
use App\Http\Resources\LecturaResource;
use App\UseCases\Lecturas\RegistrarLecturaUseCase;
use Illuminate\Http\JsonResponse;

class LecturaController extends Controller
{
    public function store(RegistrarLecturaRequest $request, RegistrarLecturaUseCase $registrar): JsonResponse
    {
        $resultado = $registrar->ejecutar($request->toData());

        return response()->json([
            'guardada' => true,
            'duplicada' => $resultado->duplicada,
            'lectura' => LecturaResource::make($resultado->lectura)->resolve(),
        ], $resultado->duplicada ? 200 : 201, [], JSON_UNESCAPED_UNICODE);
    }
}
