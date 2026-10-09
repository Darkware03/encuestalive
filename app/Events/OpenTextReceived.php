<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;

/**
 * Texto libre recibido. CANAL PRIVADO del administrador.
 *
 * El texto viaja EN CRUDO hacia un cliente ya autenticado, y Livewire lo
 * escapa al renderizar con `{{ }}`. Sanear en el transporte destruiria el dato
 * original del participante y daria falsa seguridad: el escapado correcto es
 * el de SALIDA, en el punto donde se decide el contexto (ARCHITECTURE.md §3.2).
 *
 * NO lleva identificador de sesion, ni de respuesta, ni de participante. El
 * admin no puede ligar un texto con las demas respuestas de la misma persona
 * desde el feed en vivo (ARCHITECTURE.md §4.3).
 *
 * Solo se emite si el texto, tras `trim`, no esta vacio (API.md §5.3).
 */
class OpenTextReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly string $surveyPublicId,
        public readonly int $questionId,
        public readonly string $text,
        public readonly Carbon $receivedAt,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('survey.' . $this->surveyPublicId . '.admin');
    }

    public function broadcastAs(): string
    {
        return 'text.received';
    }

    public function broadcastWith(): array
    {
        return [
            'survey_public_id' => $this->surveyPublicId,
            'question_id' => $this->questionId,
            'text' => $this->text,
            'received_at' => $this->receivedAt->toIso8601ZuluString(),
        ];
    }
}
