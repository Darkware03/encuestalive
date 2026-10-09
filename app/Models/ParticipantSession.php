<?php

namespace App\Models;

use Database\Factories\ParticipantSessionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Sesion de participacion anonima (PRD §8.0, ADR-0004, ADR-0005).
 *
 * NO contiene ningun dato personal, ni IP, ni user-agent. Si alguien anade aqui
 * una propiedad de red, PrivacySeparationTest falla.
 */
class ParticipantSession extends Model
{
    /** @use HasFactory<ParticipantSessionFactory> */
    use HasFactory;

    public const STATE_STARTED = 'started';
    public const STATE_IN_PROGRESS = 'in_progress';
    public const STATE_COMPLETED = 'completed';

    public const STATES = [
        self::STATE_STARTED,
        self::STATE_IN_PROGRESS,
        self::STATE_COMPLETED,
    ];

    /**
     * Presencia. DATABASE.md §10.3 dejo esta discrepancia abierta (ADR-0004
     * proponia 15/45, el PRD §8.1 proponia 20/60) y pidio a darkware que la
     * resolviera.
     *
     * RESUELTA POR D-12, vinculante: GANA EL PRD, 20 s de renovacion y 60 s de
     * expiracion. Motivo registrado: el participante tipico esta en un evento
     * con datos moviles saturados; una ventana de 45 s declara "desconectado" a
     * quien solo tuvo un bache de red e infla la metrica de pendientes.
     *
     * Estas constantes son el VALOR POR DEFECTO. La fuente operativa es
     * config/encuestalive.php, para que el plazo se ajuste sin tocar codigo.
     * No afectan al esquema: "conectada" se deriva de last_seen_at en cada
     * consulta y no hay contador almacenado.
     */
    public const HEARTBEAT_INTERVAL_SECONDS = 20;
    public const EXPIRY_WINDOW_SECONDS = 60;

    public static function heartbeatIntervalSeconds(): int
    {
        return (int) config(
            'encuestalive.presence.heartbeat_interval_seconds',
            self::HEARTBEAT_INTERVAL_SECONDS,
        );
    }

    public static function expiryWindowSeconds(): int
    {
        return (int) config(
            'encuestalive.presence.expiry_window_seconds',
            self::EXPIRY_WINDOW_SECONDS,
        );
    }

    protected $fillable = [
        'survey_id',
        'token_hash',
        'submission_state',
        'first_seen_at',
        'last_seen_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * El hash nunca debe salir en un payload, un log, un CSV ni un evento de
     * broadcasting (ADR-0005). Se oculta por defecto en las serializaciones.
     */
    protected $hidden = ['token_hash'];

    // ---------------------------------------------------------------- relaciones

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    /** ADR-0005 capa 3: como maximo una, por UNIQUE(participant_session_id). */
    public function response(): HasOne
    {
        return $this->hasOne(Response::class);
    }

    // ------------------------------------------------------------------- scopes

    /**
     * PRD §8.1 / ADR-0004. `conectadas` = sesiones cuya ultima senal cae dentro de
     * la ventana. Se apoya en el indice (survey_id, last_seen_at).
     * El instante limite se calcula en PHP: sin strftime ni DATE_SUB (ADR-0003).
     */
    public function scopeConnected(Builder $query, ?\DateTimeInterface $now = null): Builder
    {
        $threshold = \Illuminate\Support\Carbon::instance(
            $now ? \Illuminate\Support\Carbon::instance($now) : now()
        )->subSeconds(self::expiryWindowSeconds());

        return $query->where('last_seen_at', '>', $threshold);
    }

    /** PRD §8.2: participaron = al menos una interaccion de respuesta. */
    public function scopeParticipated(Builder $query): Builder
    {
        return $query->whereIn('submission_state', [
            self::STATE_IN_PROGRESS,
            self::STATE_COMPLETED,
        ]);
    }

    /** PRD §8.3: completadas. */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('submission_state', self::STATE_COMPLETED);
    }

    /** PRD §8.4: pendientes = conectadas AHORA que aun no completaron. */
    public function scopePending(Builder $query, ?\DateTimeInterface $now = null): Builder
    {
        return $query->connected($now)
            ->where('submission_state', '!=', self::STATE_COMPLETED);
    }

    public function scopeForSurvey(Builder $query, int $surveyId): Builder
    {
        return $query->where('survey_id', $surveyId);
    }

    // ------------------------------------------------------------------ helpers

    /** ADR-0005: en BD vive el SHA-256, jamas el token en claro. */
    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    public function isConnected(?\DateTimeInterface $now = null): bool
    {
        $now = $now ? \Illuminate\Support\Carbon::instance($now) : now();

        return $this->last_seen_at !== null
            && $this->last_seen_at->gt($now->copy()->subSeconds(self::expiryWindowSeconds()));
    }
}
