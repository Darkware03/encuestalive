<?php

namespace App\Models;

use Database\Factories\SecurityEventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * ISLA B. Registro tecnico de seguridad (brief §5.1, ARCHITECTURE.md §7).
 *
 * Unico lugar del sistema donde vive una direccion IP.
 *
 * Este modelo NO declara ninguna relacion hacia ParticipantSession, Response ni
 * ResponseAnswer, y la tabla no tiene columnas para ello. La separacion no se
 * sostiene en "no consultamos eso", sino en que la consulta no se puede escribir.
 */
class SecurityEvent extends Model
{
    /** @use HasFactory<SecurityEventFactory> */
    use HasFactory;

    public const TYPE_RATE_LIMITED = 'rate_limited';
    public const TYPE_AUTH_FAILED = 'auth_failed';
    public const TYPE_INVALID_PUBLIC_ID = 'invalid_public_id';
    public const TYPE_FOREIGN_OPTION = 'foreign_option';
    public const TYPE_TAMPERED_COOKIE = 'tampered_cookie';
    public const TYPE_EXPORT_DENIED = 'export_denied';

    public const TYPES = [
        self::TYPE_RATE_LIMITED,
        self::TYPE_AUTH_FAILED,
        self::TYPE_INVALID_PUBLIC_ID,
        self::TYPE_FOREIGN_OPTION,
        self::TYPE_TAMPERED_COOKIE,
        self::TYPE_EXPORT_DENIED,
    ];

    /**
     * ARCHITECTURE §7.3 / brief §5.3: retencion configurable.
     * Variable de entorno declarada: SECURITY_LOG_RETENTION_DAYS.
     */
    public const DEFAULT_RETENTION_DAYS = 30;

    protected $fillable = [
        'occurred_at',
        'event_type',
        'ip_address',
        'route',
        'http_status',
        'reason',
        'correlation_id',
        'user_id',
        'survey_id',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'http_status' => 'integer',
            'survey_id' => 'integer',
        ];
    }

    /** La IP nunca debe viajar en una serializacion accidental (CA-11). */
    protected $hidden = ['ip_address'];

    /** Administrador autenticado, nunca un participante anonimo. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * ARCHITECTURE §7.3: occurred_at se trunca al MINUTO. Degrada la correlacion
     * temporal con responses.completed_at (que conserva el segundo) sin impedir
     * la investigacion de abuso, que opera en ventanas de minutos.
     */
    public static function truncateToMinute(\DateTimeInterface $moment): Carbon
    {
        return Carbon::instance($moment)->startOfMinute();
    }

    protected static function booted(): void
    {
        static::saving(function (self $event): void {
            if ($event->occurred_at instanceof \DateTimeInterface) {
                $event->occurred_at = self::truncateToMinute($event->occurred_at);
            }
        });
    }

    // ------------------------------------------------------------------- scopes

    /** Purga por retencion: php artisan encuestalive:prune-security-events */
    public function scopeOlderThan(Builder $query, \DateTimeInterface $cutoff): Builder
    {
        return $query->where('occurred_at', '<', $cutoff);
    }

    public function scopeOfType(Builder $query, string ...$types): Builder
    {
        return $query->whereIn('event_type', $types);
    }
}
