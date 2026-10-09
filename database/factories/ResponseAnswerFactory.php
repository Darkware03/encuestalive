<?php

namespace Database\Factories;

use App\Models\Question;
use App\Models\Response;
use App\Models\ResponseAnswer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResponseAnswer>
 */
class ResponseAnswerFactory extends Factory
{
    protected $model = ResponseAnswer::class;

    public function definition(): array
    {
        return [
            'response_id' => Response::factory(),
            'question_id' => Question::factory(),
            'text_answer' => null,
        ];
    }

    public function withText(?string $text = null): static
    {
        return $this->state(fn () => [
            'text_answer' => $text ?? $this->faker->sentence(12),
        ]);
    }
}
