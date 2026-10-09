<?php

namespace App\Domain\Presence;

use App\Models\ParticipantSession;
use App\Models\Survey;
use Illuminate\Support\Carbon;

/**
 * Presencia por heartbeat en base de datos (ADR-0004, D-12).
 *
 * Sin canal `presence-` de Pusher y sin Redis. El motivo esta en ADR-0004 y es
 * solido: el estado de un canal de presencia vive en la MEMORIA del proceso
 * Reverb, se pierde al reiniciarlo y no existe en modo respaldo. Un heartbeat
 * en base de datos funciona identicamente en ambos modos y sobrevive a
 * cualquier reinicio, que es lo que CA-15 exige.
 *
 * Consecuencia de diseno que conviene subrayar: NO HAY ESTADO "DESCONECTADO"
 * que mantener coherente. Una desconexion es simplemente un `last_seen_at` que
 * deja de actualizarse. No hay transicion que perder ni limpieza que ejecutar,
 * y por eso la reconciliacion tras un reinicio es una consulta, no un proceso
 * de recuperacion (DATABASE.md §11.8).
 */
class PresenceService
{
    public function heartbeatIntervalSeconds(): int
    {
        return ParticipantSession::heartbeatIntervalSeconds();
    }

    public function expiryWindowSeconds(): int
    {
        return ParticipantSession::expiryWindowSeconds();
    }

    /**
     * Renueva la presencia de una sesion.
     *
     * Escritura minima y deliberada: solo `last_seen_at`. El heartbeat es el
     * mayor consumidor de escrituras del sistema (ADR-0004) y SQLite tiene un
     * unico escritor; tocar columnas de mas aqui multiplicaria el riesgo de
     * `database is locked` por nada.
     *
     * Devuelve true si el recuento de conectadas pudo cambiar, para que la
     * capa HTTP decida si emite `stats.updated`. El servicio no emite eventos
     * por su cuenta: no sabe, ni debe saber, si hay un admin escuchando.
     */
    public function touch(ParticipantSession $session, ?Carbon $now = null): bool
    {
        $now = $now ? $now->copy() : Carbon::now();

        $wasConnected = $session->isConnected($now);

        $session->forceFill(['last_seen_at' => $now])->save();

        // Si estaba expirada y vuelve, el recuento de conectadas sube.
        return ! $wasConnected;
    }

    /**
     * Marca que la sesion empezo a interactuar (PRD §8.2 "participaron").
     * Nunca degrada el estado: una sesion completada no vuelve a en_progreso.
     */
    public function markInProgress(ParticipantSession $session): void
    {
        if ($session->submission_state === ParticipantSession::STATE_STARTED) {
            $session->forceFill([
                'submission_state' => ParticipantSession::STATE_IN_PROGRESS,
            ])->save();
        }
    }

    /**
     * Recuento de conectadas. Deriva de `last_seen_at`, nunca de un contador.
     *
     * CA-15 por construccion: tras reiniciar la aplicacion, la base de datos o
     * Reverb, la PRIMERA llamada devuelve el valor correcto sin ninguna accion
     * de recuperacion, porque no habia nada en memoria que perder.
     */
    public function connectedCount(Survey $survey, ?Carbon $now = null): int
    {
        return ParticipantSession::query()
            ->where('survey_id', $survey->id)
            ->connected($now)
            ->count();
    }

    /**
     * Reconciliacion explicita tras un reinicio.
     *
     * No hace nada, Y ESO ES LA RESPUESTA. Existe como metodo con nombre para
     * que quede documentado en el codigo que la ausencia de trabajo aqui es
     * una propiedad del diseno y no un olvido: expirar no borra ni actualiza
     * nada, la fila conserva su estado y sigue contando en `participaron`,
     * `completadas` y `sesiones_iniciadas`.
     */
    public function reconcile(Survey $survey, ?Carbon $now = null): int
    {
        return $this->connectedCount($survey, $now);
    }
}
