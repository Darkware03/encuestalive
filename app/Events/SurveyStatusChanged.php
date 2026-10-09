<?php

namespace App\Events;

use App\Models\Survey;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;

/**
 * Cambio de ciclo de vida de una encuesta. CANAL PUBLICO.
 *
 * ============================ AVISO ============================
 * Este es el UNICO evento que viaja por un canal que cualquiera puede
 * escuchar, incluido un anonimo. Su carga util esta cerrada a TRES CLAVES y
 * se construye como ARRAY LITERAL.
 *
 * Esta PROHIBIDO devolver `$this->survey->toArray()` o serializar el modelo
 * (ARCHITECTURE.md §4.4, API.md §5.4). Un modelo serializado arrastraria
 * `id`, `created_by_user_id`, `snapshot_*` y las fechas internas, que son
 * datos administrativos, por un canal sin autorizacion.
 *
 * Lista de denegacion del canal publico: contenido o agregados de respuestas,
 * recuentos, porcentajes, textos libres, tokens de participante o sus hashes,
 * IP, user-agent, datos de security_events, ids internos de encuesta, datos
 * del administrador, estado de borradores y fechas no publicas.
 *
 * Sirve solo para que la pantalla del participante reaccione a un cierre en
 * vivo. Nada mas.
 * ===============================================================
 *
 * `ShouldBroadcastNow` y no `ShouldBroadcast`: no hay worker de colas en este
 * MVP, QUEUE_CONNECTION=sync (ARCHITECTURE.md §14).
 */
class SurveyStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly string $surveyPublicId,
        public readonly string $status,
        public readonly Carbon $changedAt,
    ) {}

    public static function fromSurvey(Survey $survey): self
    {
        return new self(
            surveyPublicId: (string) $survey->public_id,
            status: $survey->status,
            changedAt: Carbon::now(),
        );
    }

    /**
     * Solo se emite para estados PUBLICAMENTE OBSERVABLES.
     * Nunca para draft, scheduled ni archived: anunciar un borrador revelaria
     * que existe una encuesta que todavia no deberia ser visible.
     */
    public static function isBroadcastableStatus(string $status): bool
    {
        return in_array($status, [
            Survey::STATUS_ACTIVE,
            Survey::STATUS_PAUSED,
            Survey::STATUS_CLOSED,
        ], true);
    }

    public function broadcastOn(): Channel
    {
        return new Channel('survey.' . $this->surveyPublicId);
    }

    public function broadcastAs(): string
    {
        return 'status.changed';
    }

    /**
     * Esquema cerrado: EXACTAMENTE tres claves, en este orden.
     * `qa-engineer` tiene encargada la prueba
     * `array_keys($payload) === ['survey_public_id','status','changed_at']`.
     */
    public function broadcastWith(): array
    {
        return [
            'survey_public_id' => $this->surveyPublicId,
            'status' => $this->status,
            'changed_at' => $this->changedAt->toIso8601ZuluString(),
        ];
    }
}
