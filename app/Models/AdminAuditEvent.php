<?php

namespace App\Models;

use Database\Factories\AdminAuditEventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evento administrativo (brief §8 "eventos administrativos", RF-13, RF-54).
 * Quien hizo que con una encuesta. SIN IP: eso es isla B (SecurityEvent).
 */
class AdminAuditEvent extends Model
{
    /** @use HasFactory<AdminAuditEventFactory> */
    use HasFactory;

    public const ACTION_CREATED = 'survey.created';
    public const ACTION_PUBLISHED = 'survey.published';
    public const ACTION_SCHEDULED = 'survey.scheduled';
    public const ACTION_PAUSED = 'survey.paused';
    public const ACTION_RESUMED = 'survey.resumed';
    public const ACTION_CLOSED = 'survey.closed';
    public const ACTION_ARCHIVED = 'survey.archived';
    public const ACTION_UNARCHIVED = 'survey.unarchived';
    public const ACTION_EDITED = 'survey.edited';
    public const ACTION_DUPLICATED = 'survey.duplicated';
    public const ACTION_EXPORTED = 'survey.exported';

    public const ACTIONS = [
        self::ACTION_CREATED,
        self::ACTION_PUBLISHED,
        self::ACTION_SCHEDULED,
        self::ACTION_PAUSED,
        self::ACTION_RESUMED,
        self::ACTION_CLOSED,
        self::ACTION_ARCHIVED,
        self::ACTION_UNARCHIVED,
        self::ACTION_EDITED,
        self::ACTION_DUPLICATED,
        self::ACTION_EXPORTED,
    ];

    protected $fillable = [
        'user_id',
        'survey_id',
        'survey_title_snapshot',
        'action',
        'metadata',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            // Portable: TEXT en SQLite, JSON nativo en MySQL 8.
            // Nunca se consulta por dentro desde SQL (ADR-0003).
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function scopeForSurvey(Builder $query, int $surveyId): Builder
    {
        return $query->where('survey_id', $surveyId)->orderByDesc('occurred_at');
    }

    public function scopeAction(Builder $query, string ...$actions): Builder
    {
        return $query->whereIn('action', $actions);
    }
}
