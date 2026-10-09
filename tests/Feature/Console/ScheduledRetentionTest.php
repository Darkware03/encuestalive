<?php

namespace Tests\Feature\Console;

use App\Models\SecurityEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F-13 (ALTO, Fase 7) — LA RETENCION DE 30 DIAS NO SE EJECUTABA JAMAS.
 *
 * ======================================================================
 *  POR QUE ESTA PRUEBA EXISTE
 * ======================================================================
 * Los comandos de purga estaban escritos y probados. Lo que faltaba era que
 * alguien los llamara: `php artisan schedule:list` devolvia
 * "No scheduled tasks have been defined".
 *
 * Es el tipo de fallo que NO produce ningun sintoma visible. Nada se rompe,
 * nada da error, ninguna prueba se pone roja. Solo se descubre el dia que
 * alguien pregunta por que hay direcciones IP de hace ocho meses en una base
 * de datos cuya pagina publica de privacidad promete borrarlas a los 30 dias
 * (D-12, brief §5.3).
 *
 * Todas las pruebas de este fichero FALLAN con el codigo anterior a la
 * Fase 7b, donde `routes/console.php` solo contenia el comando `inspire`.
 *
 * ----------------------------------------------------------------------
 * LO QUE ESTA PRUEBA **NO** DEMUESTRA
 * ----------------------------------------------------------------------
 * Que la purga se dispare sola cada noche. Eso exige que el sistema invoque
 * `schedule:run` cada minuto, y EN ESTE HOST NO HAY CRON NI SYSTEMD, ni root
 * para instalarlos. Aqui se verifica el registro y el efecto del comando; el
 * disparo periodico queda como limitacion declarada para la Fase 8.
 */
class ScheduledRetentionTest extends TestCase
{
    use RefreshDatabase;

    /** Los dos comandos que D-12 obliga a ejecutar de forma recurrente. */
    private const REQUIRED_COMMANDS = [
        'encuestalive:prune-security-events',
        'encuestalive:prune-sessions',
    ];

    /**
     * @return array<int, Event>
     */
    private function scheduledEvents(): array
    {
        // Resolver el Schedule del contenedor obliga a que el kernel de
        // consola haya cargado routes/console.php, que es donde vive el
        // registro. No se lee una copia del fichero: se inspecciona el
        // planificador real de la aplicacion.
        $this->artisan('schedule:list')->run();

        return app(Schedule::class)->events();
    }

    private function eventFor(string $command): ?Event
    {
        foreach ($this->scheduledEvents() as $event) {
            if (str_contains((string) $event->command, $command)) {
                return $event;
            }
        }

        return null;
    }

    /**
     * 1. EL CRITERIO LITERAL DEL HALLAZGO — `schedule:list` los contiene.
     *
     * FALLA antes: la salida era "No scheduled tasks have been defined".
     */
    public function test_schedule_list_contains_both_retention_commands(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('encuestalive:prune-security-events')
            ->expectsOutputToContain('encuestalive:prune-sessions')
            ->assertExitCode(0);
    }

    /**
     * 2. El planificador no esta vacio y registra exactamente los comandos
     *    exigidos por D-12.
     *
     * FALLA antes: `events()` devolvia un array vacio.
     */
    public function test_the_scheduler_is_not_empty_and_registers_the_required_commands(): void
    {
        $events = $this->scheduledEvents();

        $this->assertNotEmpty(
            $events,
            'El planificador esta VACIO. Los comandos de purga existen pero nadie '
            .'los invoca: la retencion de 30 dias de D-12 no se aplica nunca y la '
            .'pagina publica de privacidad promete algo falso (F-13).',
        );

        foreach (self::REQUIRED_COMMANDS as $command) {
            $this->assertNotNull($this->eventFor($command), sprintf(
                'El comando %s no esta programado. Registrarlo en routes/console.php.',
                $command,
            ));
        }
    }

    /**
     * 3. Se ejecutan a diario, no "alguna vez".
     *
     * Una tarea registrada con una expresion cron equivocada (por ejemplo
     * anual) pasaria la prueba 2 y seguiria incumpliendo D-12. Se comprueba la
     * frecuencia efectiva, no solo la presencia.
     */
    public function test_both_retention_commands_run_daily(): void
    {
        foreach (self::REQUIRED_COMMANDS as $command) {
            $event = $this->eventFor($command);
            $this->assertNotNull($event, $command.' no esta programado.');

            $parts = preg_split('/\s+/', trim($event->expression));

            $this->assertCount(5, $parts, 'Expresion cron mal formada: '.$event->expression);

            // minuto y hora concretos, y "todos los dias" en los otros tres campos.
            $this->assertNotSame('*', $parts[0], 'El minuto debe ser concreto: '.$event->expression);
            $this->assertNotSame('*', $parts[1], 'La hora debe ser concreta: '.$event->expression);
            $this->assertSame(['*', '*', '*'], array_slice($parts, 2, 3), sprintf(
                'La purga de %s debe correr TODOS los dias; expresion actual: %s',
                $command,
                $event->expression,
            ));
        }
    }

    /**
     * 4. No se solapan entre si.
     *
     * SQLite admite UN solo escritor (riesgo R3 del equipo). Dos purgas a la
     * misma hora compiten por el mismo bloqueo. Se exige separacion horaria.
     */
    public function test_the_two_purges_do_not_run_at_the_same_minute(): void
    {
        $expressions = array_map(
            fn (string $c) => $this->eventFor($c)?->expression,
            self::REQUIRED_COMMANDS,
        );

        $this->assertNotEquals(
            $expressions[0],
            $expressions[1],
            'Las dos purgas estan programadas en el mismo instante. SQLite tiene un '
            .'unico escritor: separalas.',
        );
    }

    /**
     * 5. DE EFECTO — el comando programado purga de verdad.
     *
     * Registrar un comando que no borrara nada seria cumplir la forma y no el
     * fondo. Aqui se comprueba la consecuencia real: un evento mas antiguo que
     * el plazo desaparece; uno reciente sobrevive.
     */
    public function test_the_scheduled_purge_actually_deletes_expired_security_events(): void
    {
        $days = (int) config('encuestalive.retention.security_log_days');
        $this->assertGreaterThan(0, $days, 'La retencion debe estar configurada.');

        $old = SecurityEvent::factory()->olderThanDays($days + 5)->create();
        $recent = SecurityEvent::factory()->create();

        $this->artisan('encuestalive:prune-security-events')->assertExitCode(0);

        $this->assertDatabaseMissing('security_events', ['id' => $old->id]);
        $this->assertDatabaseHas('security_events', ['id' => $recent->id]);
    }

    /**
     * 6. La promesa publica y la configuracion dicen el MISMO numero.
     *
     * F-13 es, antes que nada, un problema de honestidad: la pagina de
     * privacidad le dice un plazo al participante. Si alguien cambia
     * SECURITY_LOG_RETENTION_DAYS y la pagina sigue diciendo otra cosa, la
     * aplicacion vuelve a mentir, esta vez por desincronizacion.
     */
    public function test_the_public_privacy_page_states_the_configured_retention(): void
    {
        $days = (int) config('encuestalive.retention.security_log_days');

        $this->get('/privacidad')
            ->assertOk()
            ->assertSee((string) $days, false);
    }
}
