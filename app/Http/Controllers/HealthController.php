<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * `GET /health` (API.md §2.1). Sin auth, para el health check de Docker.
 *
 * NO expone version, nombre de host, rutas ni recuento de registros: un health
 * check publico que detalla la pila es un regalo para quien hace
 * reconocimiento.
 *
 * `broadcasting` dice "reverb" solo si hay un conector REAL configurado. Si
 * BROADCAST_CONNECTION es `log` o `null`, declara "fallback" con honestidad:
 * decir "reverb" porque el paquete esta instalado seria afirmar algo que no se
 * ha comprobado.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $database = 'ok';

        try {
            DB::connection()->getPdo()->query('select 1');
        } catch (Throwable) {
            // Sin detalle del error: el mensaje de una excepcion de conexion
            // suele llevar la ruta del fichero o el host de la base de datos.
            $database = 'error';
        }

        $healthy = $database === 'ok';

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'database' => $database,
            'broadcasting' => config('broadcasting.default') === 'reverb' ? 'reverb' : 'fallback',
            'time' => now()->toIso8601ZuluString(),
        ], $healthy ? 200 : 503);
    }
}
