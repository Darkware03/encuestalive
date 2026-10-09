<?php

namespace App\Console\Commands;

use App\Models\SecurityEvent;
use Illuminate\Console\Command;

/**
 * Purga de la isla B por retencion (D-12, brief §5.3).
 *
 * Encargo que DATABASE.md §8.2 dejo explicitamente abierto para
 * `backend-engineer`: los scopes existian y estaban probados, pero el comando
 * no estaba escrito.
 *
 * Borrado DURO: `security_events` es el unico almacen de IP del modelo, asi
 * que aqui no hay desvinculacion que valga. Pasado el plazo, la fila
 * desaparece.
 *
 * 30 dias por defecto (D-12 enmendo D-05, que decia 90): privacidad desde el
 * diseno, y ante la duda el plazo mas corto es el mas defendible.
 * [SUPUESTO - requiere validacion legal]
 */
class PruneSecurityEventsCommand extends Command
{
    protected $signature = 'encuestalive:prune-security-events
        {--days= : Dias de retencion (por defecto, SECURITY_LOG_RETENTION_DAYS)}
        {--dry-run : Cuenta lo que se borraria, sin borrar nada}';

    protected $description = 'Borra los registros de seguridad que superan el plazo de retencion.';

    public function handle(): int
    {
        $days = (int) ($this->option('days')
            ?: config('encuestalive.retention.security_log_days'));

        if ($days < 1) {
            $this->components->error('El plazo de retencion debe ser de al menos 1 dia.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $query = SecurityEvent::query()->olderThan($cutoff);

        $count = $query->count();

        if ($this->option('dry-run')) {
            $this->components->info(sprintf(
                '[simulacion] Se borrarian %d registros anteriores a %s.',
                $count,
                $cutoff->toDateTimeString(),
            ));

            return self::SUCCESS;
        }

        $deleted = $query->delete();

        $this->components->info(sprintf(
            'Borrados %d registros de seguridad anteriores a %s (retencion: %d dias).',
            $deleted,
            $cutoff->toDateTimeString(),
            $days,
        ));

        return self::SUCCESS;
    }
}
