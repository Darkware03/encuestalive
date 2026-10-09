<?php

namespace Database\Factories;

use App\Models\ParticipantSession;
use App\Models\Survey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ParticipantSession>
 *
 * Jamas genera IP, user-agent ni dato personal alguno: la tabla no tiene donde
 * ponerlos. El token sintetico es aleatorio y solo se almacena su SHA-256.
 */
class ParticipantSessionFactory extends Factory
{
    protected $model = ParticipantSession::class;

    public function definition(): array
    {
        return [
            'survey_id' => Survey::factory()->published(),
            'token_hash' => ParticipantSession::hashToken(bin2hex(random_bytes(32))),
            'submission_state' => ParticipantSession::STATE_STARTED,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'completed_at' => null,
        ];
    }

    /** Dentro de la ventana de expiracion -> cuenta en `conectadas`. */
    public function connected(): static
    {
        return $this->state(fn () => ['last_seen_at' => now()]);
    }

    /** Fuera de la ventana -> deja de contar en `conectadas` (no se borra). */
    public function expired(): static
    {
        return $this->state(fn () => [
            'last_seen_at' => now()->subSeconds(ParticipantSession::EXPIRY_WINDOW_SECONDS + 15),
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn () => [
            'submission_state' => ParticipantSession::STATE_IN_PROGRESS,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'submission_state' => ParticipantSession::STATE_COMPLETED,
            'completed_at' => now(),
        ]);
    }
}
