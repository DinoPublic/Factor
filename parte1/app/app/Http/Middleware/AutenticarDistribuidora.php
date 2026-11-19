<?php

namespace App\Http\Middleware;

use App\Enums\MotivoRechazo;
use App\Exceptions\LecturaRechazadaException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AutenticarDistribuidora
{
    public function handle(Request $request, Closure $next): Response
    {
        $esperado = (string) config('lecturas.api_token');
        $recibido = (string) $request->bearerToken();

        if ($esperado === '' || ! hash_equals($esperado, $recibido)) {
            return LecturaRechazadaException::respuesta(
                MotivoRechazo::NoAutorizado,
                'Token de acceso ausente o no válido.',
            );
        }

        return $next($request);
    }
}
