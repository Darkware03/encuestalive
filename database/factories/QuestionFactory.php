<?php

namespace Database\Factories;

use App\Models\Question;
use App\Models\Survey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Question>
 */
class QuestionFactory extends Factory
{
    protected $model = Question::class;

    public function definition(): array
    {
        return [
            'survey_id' => Survey::factory(),
            'type' => Question::TYPE_SINGLE_CHOICE,
            'prompt' => rtrim($this->faker->sentence(6), '.').'?',
            'is_required' => true,
            'position' => 1,
            'min_selections' => null,
            'max_selections' => null,
            'min_length' => null,
            'max_length' => null,
        ];
    }

    public function singleChoice(): static
    {
        return $this->state(fn () => [
            'type' => Question::TYPE_SINGLE_CHOICE,
            'min_selections' => null,
            'max_selections' => null,
        ]);
    }

    /** RN-01.5: 1 <= min <= max <= nº de opciones. */
    public function multipleChoice(int $min = 1, int $max = 3): static
    {
        return $this->state(fn () => [
            'type' => Question::TYPE_MULTIPLE_CHOICE,
            'min_selections' => $min,
            'max_selections' => $max,
        ]);
    }

    /** RN-01.6: 0 <= min_length < max_length <= 2000. */
    public function freeText(int $min = 0, int $max = Question::DEFAULT_TEXT_ANSWER_LENGTH): static
    {
        return $this->state(fn () => [
            'type' => Question::TYPE_FREE_TEXT,
            'min_length' => $min,
            'max_length' => $max,
        ]);
    }

    public function optional(): static
    {
        return $this->state(fn () => ['is_required' => false]);
    }

    public function atPosition(int $position): static
    {
        return $this->state(fn () => ['position' => $position]);
    }
}
