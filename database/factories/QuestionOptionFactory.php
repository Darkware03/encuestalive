<?php

namespace Database\Factories;

use App\Models\Question;
use App\Models\QuestionOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuestionOption>
 */
class QuestionOptionFactory extends Factory
{
    protected $model = QuestionOption::class;

    public function definition(): array
    {
        return [
            'question_id' => Question::factory(),
            'label' => ucfirst($this->faker->unique()->words(2, true)),
            'position' => 1,
        ];
    }

    public function atPosition(int $position): static
    {
        return $this->state(fn () => ['position' => $position]);
    }

    public function label(string $label): static
    {
        return $this->state(fn () => ['label' => $label]);
    }
}
