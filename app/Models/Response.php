<?php

namespace App\Models;

use Database\Factories\ResponseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Envio completado (PRD §8.3). Una fila = un envio finalizado y persistido.
 * No existen envios parciales en esta tabla (RN-12: validacion atomica).
 *
 * Sin columna de IP. La unica via para llegar desde aqui a un dato de red seria
 * una columna que no existe en ninguna tabla de la cadena.
 */
class Response extends Model
{
    /** @use HasFactory<ResponseFactory> */
    use HasFactory;

    protected $fillable = [
        'survey_id',
        'participant_session_id',
        'submission_uuid',
        'survey_version_number',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
            'survey_version_number' => 'integer',
        ];
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    /** Nullable tras la purga de RN-09.5: el envio sobrevive, desvinculado. */
    public function participantSession(): BelongsTo
    {
        return $this->belongsTo(ParticipantSession::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(ResponseAnswer::class);
    }

    // ------------------------------------------------------------------- scopes

    public function scopeForSurvey(Builder $query, int $surveyId): Builder
    {
        return $query->where('survey_id', $surveyId);
    }

    /**
     * Grafico de evolucion (ADR-0003: el agrupado temporal se hace en PHP sobre
     * completed_at ya recuperado; prohibido DATE_FORMAT/strftime/DATE_TRUNC).
     */
    public function scopeCompletedBetween(
        Builder $query,
        \DateTimeInterface $from,
        \DateTimeInterface $to
    ): Builder {
        return $query->whereBetween('completed_at', [$from, $to])
            ->orderBy('completed_at');
    }

    /** RN-09.5: envios ya desvinculados de su sesion. */
    public function scopeUnlinked(Builder $query): Builder
    {
        return $query->whereNull('participant_session_id');
    }
}
