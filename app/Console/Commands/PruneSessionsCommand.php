<?php

namespace App\Console\Commands;

use App\Domain\Stats\StatsService;
use App\Models\Survey;
use Illuminate\Console\Command;

/**
 * Purga de metadatos de sesion de participacion (RN-09.5).
 *
 * ============ DESVINCULAR, NO BORRAR. ESA ES LA PIEZA CLAVE ============
 * `responses.participant_session_id` es ON DELETE SET NULL. Al borrar la
 * sesion, el motor DESVINCULA el envio y lo conserva: la respuesta sobrevive,
 * huerfana y por tanto imposible de atribuir, y los resultados de la encuesta
 * no cambian ni un numero.
 *
 * Se purga lo que identifica una sesion a lo largo del tiempo, no lo que la
 * gente respondio. Son dos cosas distintas y solo una caduca.
 * =======================================================================
 *
 * El reloj es `surveys.closed_at`, NO `archived_at` (RN-10.8): archivar es
 * organizativo y no debe acelerar ni retrasar la retencion.
 *
 * Antes de purgar, se comprueba que `snapshot_*` este escrito. Sin esa
 * instantanea, la tasa de respuesta de la encuesta pasaria de un numero a
 * "indefinida" el dia de la purga, porque desapareceria el denominador.
 */
class PruneSessionsCommand extends Command
{
    protected $signature = 'encuestalive:prune-sessions
        {--days= : Dias tras el CIERRE (por defecto, SESSION_RETENTION_DAYS)}
        {--dry-run : Cuenta lo que se purgaria, sin tocar nada}';

    protected $description = 'Desvincula y borra los metadatos de sesion de encuestas cerradas hace tiempo.';

    public function handle(StatsService $stats): int
    {
        $days = (int) ($this->option('days')
            ?: config('encuestalive.retention.session_days'));

        if ($days < 1) {
            $this->components->error('El plazo de retencion debe ser de al menos 1 dia.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        $surveys = Survey::query()->sessionsPurgeable($cutoff)->get();

        if ($surveys->isEmpty()) {
            $this->components->info('No hay encuestas con sesiones purgables.');

            return self::SUCCESS;
        }

        $totalSessions = 0;

        foreach ($surveys as $survey) {
            $sessionCount = $survey->participantSessions()->count();

            if ($sessionCount === 0) {
                continue;
            }

            if ($dryRun) {
                $this->line(sprintf(
                    '  [simulacion] #%d "%s": %d sesiones',
                    $survey->id,
                    $survey->title,
                    $sessionCount,
                ));
                $totalSessions += $sessionCount;

                continue;
            }

            // Red de seguridad: si el cierre no dejo la instantanea escrita
            // (por ejemplo, una encuesta cerrada por una version anterior),
            // se escribe AHORA, antes de que desaparezca el denominador.
            if ($survey->snapshot_sessions_started === null) {
                $stats->writeClosingSnapshot($survey);
                $this->line(sprintf('  #%d: instantanea escrita antes de purgar.', $survey->id));
            }

            // El SET NULL del motor desvincula los envios por nosotros.
            $survey->participantSessions()->delete();
            $totalSessions += $sessionCount;

            $this->line(sprintf(
                '  #%d "%s": %d sesiones purgadas, envios conservados.',
                $survey->id,
                $survey->title,
                $sessionCount,
            ));
        }

        $this->components->info(sprintf(
            '%s %d sesiones de %d encuestas cerradas antes de %s (retencion: %d dias).',
            $dryRun ? '[simulacion] Se purgarian' : 'Purgadas',
            $totalSessions,
            $surveys->count(),
            $cutoff->toDateTimeString(),
            $days,
        ));

        return self::SUCCESS;
    }
}
