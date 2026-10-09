<?php

namespace App\Events;

use App\Domain\Stats\StatsSnapshot;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Metricas actualizadas. CANAL PRIVADO del administrador.
 *
 * ================= EL PAYLOAD NO ES LA VERDAD =================
 * D-02, regla dura e innegociable: este evento es una SENAL ENRIQUECIDA, no la
 * fuente de verdad. El dashboard lo usa unicamente para decidir CUANDO
 * reconsultar StatsService; jamas pinta directamente estos numeros.
 *
 * Por eso los contadores salen identicos con Reverb y con `wire:poll`, y por
 * eso un evento perdido o entregado fuera de orden no produce deriva
 * (ARCHITECTURE.md §3.3, CA-10, CA-15).
 * ==============================================================
 *
 * Viaja por canal privado autorizado con SurveyPolicy::view sobre ESA encuesta
 * concreta. No basta con estar autenticado (API.md §3.3, CA-12).
 */
class SurveyStatsUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly StatsSnapshot $stats,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('survey.' . $this->stats->surveyPublicId . '.admin');
    }

    public function broadcastAs(): string
    {
        return 'stats.updated';
    }

    /**
     * Array literal construido por el DTO (API.md §5.2). Solo agregados.
     * Ningun token, ninguna IP, ningun participant_session_id: el admin no
     * puede, ni siquiera desde el canal privado, correlacionar una respuesta
     * con la sesion que la produjo.
     */
    public function broadcastWith(): array
    {
        return $this->stats->toBroadcastArray();
    }
}
