<?php

namespace App\Models;

use Database\Factories\QuestionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Question extends Model
{
    /** @use HasFactory<QuestionFactory> */
    use HasFactory;

    public const TYPE_SINGLE_CHOICE = 'single_choice';
    public const TYPE_MULTIPLE_CHOICE = 'multiple_choice';
    public const TYPE_FREE_TEXT = 'free_text';

    public const TYPES = [
        self::TYPE_SINGLE_CHOICE,
        self::TYPE_MULTIPLE_CHOICE,
        self::TYPE_FREE_TEXT,
    ];

    public const CHOICE_TYPES = [
        self::TYPE_SINGLE_CHOICE,
        self::TYPE_MULTIPLE_CHOICE,
    ];

    /**
     * RN-01.6. SQLite no valida longitudes de VARCHAR (ADR-0003, "Negativas"),
     * asi que estos limites se aplican en PHP: son la unica barrera real aqui.
     */
    public const MAX_TEXT_ANSWER_LENGTH = 2000;
    public const DEFAULT_TEXT_ANSWER_LENGTH = 1000;

    protected $fillable = [
        'survey_id',
        'type',
        'prompt',
        'is_required',
        'position',
        'min_selections',
        'max_selections',
        'min_length',
        'max_length',
    ];

    protected function casts(): array
    {
        return [
            // ADR-0003: nunca comparar con 'true'. SQLite guarda 0/1.
            'is_required' => 'boolean',
            'position' => 'integer',
            'min_selections' => 'integer',
            'max_selections' => 'integer',
            'min_length' => 'integer',
            'max_length' => 'integer',
        ];
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class)->orderBy('position');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(ResponseAnswer::class);
    }

    // ------------------------------------------------------------------- scopes

    public function scopeOfType(Builder $query, string ...$types): Builder
    {
        return $query->whereIn('type', $types);
    }

    public function scopeChoiceBased(Builder $query): Builder
    {
        return $query->whereIn('type', self::CHOICE_TYPES);
    }

    public function scopeRequired(Builder $query): Builder
    {
        return $query->where('is_required', true);
    }

    // ------------------------------------------------------------------ helpers

    public function isChoiceBased(): bool
    {
        return in_array($this->type, self::CHOICE_TYPES, true);
    }

    public function isFreeText(): bool
    {
        return $this->type === self::TYPE_FREE_TEXT;
    }
}
