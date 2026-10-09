<?php

namespace App\Models;

use Database\Factories\SurveyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Encuesta. Raiz del agregado de la isla A.
 *
 * @property string|null $public_id ADR-0006, 16 caracteres Base32 Crockford
 */
class Survey extends Model
{
    /** @use HasFactory<SurveyFactory> */
    use HasFactory;

    // ADR-0003: nada de enum de BD. Constantes de clase + validacion en PHP.
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SCHEDULED,
        self::STATUS_ACTIVE,
        self::STATUS_PAUSED,
        self::STATUS_CLOSED,
        self::STATUS_ARCHIVED,
    ];

    /**
     * ADR-0006: alfabeto Base32 Crockford, sin I, L, O ni U.
     * El generador vive en el dominio (backend-engineer); el modelo solo publica
     * el contrato que la BD y las rutas deben respetar.
     */
    public const PUBLIC_ID_LENGTH = 16;
    public const PUBLIC_ID_PATTERN = '[0-9A-HJKMNP-TV-Z]{16}';

    /** RN-01.9: limite operativo de cordura, no tecnico. */
    public const MAX_QUESTIONS = 50;

    /**
     * RN-09.5: dias tras el CIERRE (no tras el archivado) antes de purgar los
     * metadatos de sesion. Configurable; valor por defecto de ingenieria.
     * [SUPUESTO - requiere validacion legal] (D-05 PA-06).
     */
    public const DEFAULT_SESSION_RETENTION_DAYS = 30;

    protected $fillable = [
        'public_id',
        'title',
        'description',
        'instructions',
        'status',
        'version_number',
        'parent_survey_id',
        'created_by_user_id',
        'starts_at',
        'ends_at',
        'published_at',
        'closed_at',
        'closed_by_user_id',
        'archived_at',
        'snapshot_sessions_started',
        'snapshot_participated',
        'snapshot_completed',
        'snapshot_response_rate',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'published_at' => 'datetime',
            'closed_at' => 'datetime',
            'archived_at' => 'datetime',
            'version_number' => 'integer',
            'snapshot_sessions_started' => 'integer',
            'snapshot_participated' => 'integer',
            'snapshot_completed' => 'integer',
            'snapshot_response_rate' => 'float',
        ];
    }

    // ---------------------------------------------------------------- relaciones

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)->orderBy('position');
    }

    public function participantSessions(): HasMany
    {
        return $this->hasMany(ParticipantSession::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(Response::class);
    }

    public function options(): HasManyThrough
    {
        return $this->hasManyThrough(QuestionOption::class, Question::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    /** RN-03: encuesta origen cuando esta se creo como "nueva version". */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_survey_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(self::class, 'parent_survey_id');
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(AdminAuditEvent::class);
    }

    // ------------------------------------------------------------------- scopes

    /** Listado administrativo por defecto: RN-10.2, las archivadas no aparecen. */
    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->where('status', '!=', self::STATUS_ARCHIVED);
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ARCHIVED);
    }

    /** Estado ALMACENADO activo. El estado EFECTIVO de RN-11 lo resuelve el dominio. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeStatus(Builder $query, string ...$statuses): Builder
    {
        return $query->whereIn('status', $statuses);
    }

    /** ADR-0006: resolucion de /s/{publicId}. Indice unico surveys.public_id. */
    public function scopeWithPublicId(Builder $query, string $publicId): Builder
    {
        return $query->where('public_id', $publicId);
    }

    /**
     * RN-11: encuestas cuyo `fin` ya vencio pero siguen almacenadas como abiertas.
     * Usa el indice (status, ends_at). Sin funciones de fecha de motor: el instante
     * se pasa como parametro desde PHP (regla de portabilidad de ADR-0003).
     */
    public function scopeDueToClose(Builder $query, \DateTimeInterface $now): Builder
    {
        return $query->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_PAUSED])
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', $now);
    }

    /** RN-09.5: encuestas cerradas hace mas de N dias -> sesiones purgables. */
    public function scopeSessionsPurgeable(Builder $query, \DateTimeInterface $before): Builder
    {
        return $query->whereNotNull('closed_at')->where('closed_at', '<=', $before);
    }
}
