<?php

namespace Database\Factories;

use App\Models\ParticipantSession;
use App\Models\Response;
use App\Models\Survey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Response>
 */
class ResponseFactory extends Factory
{
    protected $model = Response::class;

    public function definition(): array
    {
        return [
            'survey_id' => Survey::factory()->published(),
            'participant_session_id' => null,
            'submission_uuid' => $this->faker->uuid(),
            'survey_version_number' => 1,
            'completed_at' => now(),
        ];
    }

    /** Vincula el envio a una sesion de la MISMA encuesta (coherencia del agregado). */
    public function forSession(ParticipantSession $session): static
    {
        return $this->state(fn () => [
            'survey_id' => $session->survey_id,
            'participant_session_id' => $session->getKey(),
            'survey_version_number' => $session->survey?->version_number ?? 1,
        ]);
    }

    /** RN-09.5: envio ya purgado de metadatos de sesion. */
    public function unlinked(): static
    {
        return $this->state(fn () => ['participant_session_id' => null]);
    }
}
