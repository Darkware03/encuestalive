<?php

namespace App\Support;

use App\Models\SecurityEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Escritura en la ISLA B (`security_events`).
 *
 * ================= LA UNICA PUERTA DE ENTRADA DE LA IP =================
 * Esta clase es el unico sitio del codigo que escribe una direccion IP. Si
 * alguien quiere registrar una IP en otro lugar, tiene que crear una columna
 * que no existe, y `PrivacySeparationTest` se pondria en rojo.
 *
 * Lo que NUNCA se escribe aqui, porque la tabla no tiene columna para ello:
 * `participant_session_id`, `response_id`, `token_hash`, contenido de
 * respuestas. No hay puente entre islas (ARCHITECTURE.md §7).
 * =======================================================================
 *
 * `route` guarda el PATRON de ruta (`s/{publicId}`), nunca la URL resuelta:
 * la URL llevaria el `public_id` real y lo dejaria junto a una IP y una marca
 * temporal, que es exactamente la correlacion que §7.3 intenta degradar.
 *
 * `occurred_at` lo trunca al minuto el propio modelo.
 */
class SecurityLog
{
    public static function record(
        string $eventType,
        ?Request $request = null,
        ?int $httpStatus = null,
        ?string $reason = null,
        ?int $userId = null,
        ?int $surveyId = null,
    ): void {
        $request ??= request();

        try {
            SecurityEvent::create([
                'occurred_at' => Carbon::now(),
                'event_type' => $eventType,
                'ip_address' => $request?->ip(),
                // Patron, no URL resuelta.
                'route' => $request?->route()?->uri(),
                'http_status' => $httpStatus,
                'reason' => $reason === null ? null : mb_substr($reason, 0, 100),
                'correlation_id' => $request?->attributes->get('el.correlation_id'),
                'user_id' => $userId,
                'survey_id' => $surveyId,
            ]);
        } catch (Throwable) {
            /*
             * El registro de seguridad NUNCA puede tumbar la peticion que
             * intenta describir. Si la isla B no esta disponible, se pierde el
             * registro y la aplicacion sigue: un login correcto no debe
             * fracasar porque no se pudo anotar que ocurrio.
             *
             * Se traga en silencio a proposito: escribir en el log de
             * aplicacion desde aqui arriesgaria filtrar a un fichero lo que
             * esta tabla guarda deliberadamente aparte.
             */
        }
    }

    public static function authFailed(Request $request, string $reason = 'invalid_credentials'): void
    {
        self::record(SecurityEvent::TYPE_AUTH_FAILED, $request, 422, $reason);
    }

    public static function rateLimited(Request $request, string $limiter): void
    {
        self::record(SecurityEvent::TYPE_RATE_LIMITED, $request, 429, $limiter);
    }

    public static function invalidPublicId(Request $request): void
    {
        self::record(SecurityEvent::TYPE_INVALID_PUBLIC_ID, $request, 404);
    }

    public static function exportDenied(Request $request, ?int $userId, ?int $surveyId): void
    {
        self::record(SecurityEvent::TYPE_EXPORT_DENIED, $request, 403, null, $userId, $surveyId);
    }

    /**
     * Exportacion realizada (ARCHITECTURE.md §3.4).
     *
     * Se registra el numero de filas, no su contenido: saber que alguien
     * exporto 312 filas sirve para investigar un abuso; saber QUE decian esas
     * filas seria un segundo almacen de respuestas en la isla equivocada.
     */
    public static function exportPerformed(Request $request, int $userId, int $surveyId, int $rows): void
    {
        self::record('export_performed', $request, 200, 'rows=' . $rows, $userId, $surveyId);
    }
}
