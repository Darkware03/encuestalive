<?php

namespace App\Models;

use Database\Factories\ResponseAnswerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Respuesta a UNA pregunta dentro de un envio.
 * UNIQUE(response_id, question_id) es la restriccion que impide que una misma
 * sesion responda dos veces la misma pregunta.
 */
class ResponseAnswer extends Model
{
    /** @use HasFactory<ResponseAnswerFactory> */
    use HasFactory;

    protected $fillable = [
        'response_id',
        'question_id',
        'text_answer',
    ];

    public function response(): BelongsTo
    {
        return $this->belongsTo(Response::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function selections(): HasMany
    {
        return $this->hasMany(ResponseSelection::class);
    }

    // ------------------------------------------------------------------- scopes

    public function scopeForQuestion(Builder $query, int $questionId): Builder
    {
        return $query->where('question_id', $questionId);
    }

    /** PRD §8.6: listado de texto libre, excluyendo ausencias. */
    public function scopeWithText(Builder $query): Builder
    {
        return $query->whereNotNull('text_answer')->where('text_answer', '!=', '');
    }
}
