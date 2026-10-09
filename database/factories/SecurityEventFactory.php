<?php

namespace Database\Factories;

use App\Models\SecurityEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SecurityEvent>
 *
 * Las IP generadas son FICTICIAS (rango de documentacion RFC 5737, 192.0.2.0/24).
 * Nunca se siembran direcciones reales.
 */
class SecurityEventFactory extends Factory
{
    protected $model = SecurityEvent::class;

    public function definition(): array
    {
        return [
            // El modelo lo trunca al minuto al guardar (ARCHITECTURE §7.3).
            'occurred_at' => now(),
            'event_type' => SecurityEvent::TYPE_RATE_LIMITED,
            'ip_address' => '192.0.2.'.$this->faker->numberBetween(1, 254),
            'route' => 'POST /s/{publicId}/responses',
            'http_status' => 429,
            'reason' => 'too_many_requests',
            'correlation_id' => $this->faker->uuid(),
            'user_id' => null,
            // Entero suelto: NO hay clave foranea hacia la isla A.
            'survey_id' => null,
        ];
    }

    public function ofType(string $type, int $status = 429): static
    {
        return $this->state(fn () => [
            'event_type' => $type,
            'http_status' => $status,
        ]);
    }

    public function olderThanDays(int $days): static
    {
        return $this->state(fn () => ['occurred_at' => now()->subDays($days)]);
    }
}
