<?php

namespace Database\Factories;

use App\Models\Survey;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Survey>
 */
class SurveyFactory extends Factory
{
    protected $model = Survey::class;

    /** ADR-0006: Base32 Crockford, sin I, L, O ni U. */
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function publicId(): string
    {
        $id = '';
        foreach (str_split(bin2hex(random_bytes(Survey::PUBLIC_ID_LENGTH)), 2) as $byte) {
            $id .= self::ALPHABET[hexdec($byte) & 31];
        }

        return $id;
    }

    public function definition(): array
    {
        return [
            // Un borrador NO tiene identificador publico (ADR-0006, ciclo de vida).
            'public_id' => null,
            'title' => rtrim($this->faker->sentence(4), '.'),
            'description' => $this->faker->optional()->sentence(12),
            'instructions' => null,
            'status' => Survey::STATUS_DRAFT,
            'version_number' => 1,
            'parent_survey_id' => null,
            'created_by_user_id' => User::factory(),
            'starts_at' => null,
            'ends_at' => null,
            'published_at' => null,
            'closed_at' => null,
            'closed_by_user_id' => null,
            'archived_at' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => [
            'status' => Survey::STATUS_DRAFT,
            'public_id' => null,
            'published_at' => null,
        ]);
    }

    public function scheduled(): static
    {
        return $this->state(fn () => [
            'status' => Survey::STATUS_SCHEDULED,
            'public_id' => self::publicId(),
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(4),
        ]);
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status' => Survey::STATUS_ACTIVE,
            'public_id' => self::publicId(),
            'published_at' => now(),
            'starts_at' => now()->subMinutes(10),
            'ends_at' => now()->addHours(3),
        ]);
    }

    public function paused(): static
    {
        return $this->published()->state(fn () => ['status' => Survey::STATUS_PAUSED]);
    }

    public function closed(): static
    {
        return $this->published()->state(fn () => [
            'status' => Survey::STATUS_CLOSED,
            'closed_at' => now(),
            'ends_at' => now(),
        ]);
    }

    public function archived(): static
    {
        return $this->closed()->state(fn () => [
            'status' => Survey::STATUS_ARCHIVED,
            'archived_at' => now(),
        ]);
    }
}
