<?php

namespace App\Models;

use Database\Factories\ResponseSelectionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResponseSelection extends Model
{
    /** @use HasFactory<ResponseSelectionFactory> */
    use HasFactory;

    protected $fillable = [
        'response_answer_id',
        'question_option_id',
    ];

    public function responseAnswer(): BelongsTo
    {
        return $this->belongsTo(ResponseAnswer::class);
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(QuestionOption::class, 'question_option_id');
    }

    /**
     * Agregado principal del dashboard (PRD §8.6). Usa el indice
     * response_selections.question_option_id. Sin DB::raw: solo el query builder.
     *
     * @param  array<int,int>  $optionIds
     */
    public function scopeTalliedFor(Builder $query, array $optionIds): Builder
    {
        return $query->whereIn('question_option_id', $optionIds)
            ->groupBy('question_option_id')
            ->selectRaw('question_option_id, COUNT(*) as total');
    }
}
