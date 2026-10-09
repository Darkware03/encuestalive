<?php

namespace Database\Factories;

use App\Models\AdminAuditEvent;
use App\Models\Survey;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdminAuditEvent>
 */
class AdminAuditEventFactory extends Factory
{
    protected $model = AdminAuditEvent::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'survey_id' => Survey::factory(),
            'survey_title_snapshot' => null,
            'action' => AdminAuditEvent::ACTION_CREATED,
            'metadata' => null,
            'occurred_at' => now(),
        ];
    }

    public function action(string $action, ?array $metadata = null): static
    {
        return $this->state(fn () => [
            'action' => $action,
            'metadata' => $metadata,
        ]);
    }
}
