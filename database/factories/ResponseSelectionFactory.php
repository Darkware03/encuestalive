<?php

namespace Database\Factories;

use App\Models\QuestionOption;
use App\Models\ResponseAnswer;
use App\Models\ResponseSelection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResponseSelection>
 */
class ResponseSelectionFactory extends Factory
{
    protected $model = ResponseSelection::class;

    public function definition(): array
    {
        return [
            'response_answer_id' => ResponseAnswer::factory(),
            'question_option_id' => QuestionOption::factory(),
        ];
    }
}
