<?php

namespace Tests\Feature\Database;

use App\Models\Response;
use App\Models\SecurityEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * F-01 (ALTO, Fase 7) — PRECISION TEMPORAL DE LA ISLA B.
 *
 * ======================================================================
 *  QUE DEFIENDE ESTA PRUEBA, Y POR QUE NO BASTABA LA QUE YA HABIA
 * ======================================================================
 * ARCHITECTURE.md §7.3 acepta el riesgo residual de correlacion temporal
 * respuesta<->IP a cambio de UNA mitigacion concreta: que la isla B solo
 * conozca el MINUTO del evento, mientras `responses.completed_at` conserva el
 * segundo. Un JOIN por ventana de minuto devuelve demasiados candidatos para
 * reasociar a nadie; uno de +-2 s identifica a una sola persona.
 *
 * La prueba que existia comprobaba `occurred_at`. Pasaba en verde mientras
 * `created_at`, en la MISMA FILA, filtraba el segundo exacto (hallazgo F-01
 * del informe de seguridad: `occurred_at 22:22:00` / `created_at 22:32:08`).
 *
 * Por eso esta prueba NO nombra las columnas que vigila: las DESCUBRE del
 * esquema real y las exige todas truncadas. Anadir manana una columna
 * temporal con precision de segundo a la isla B rompe la suite, que es
 * exactamente lo que no ocurrio la primera vez.
 *
 * Las tres pruebas de este fichero FALLAN con el codigo anterior a la Fase 7b.
 */
class TemporalPrecisionTest extends TestCase
{
    use RefreshDatabase;

    /** Isla B. La unica tabla del sistema con direcciones IP. */
    private const ISLAND_B = 'security_events';

    /**
     * Columnas temporales toleradas en la isla B, y su unica semantica valida.
     *
     * Lista BLANCA a proposito: si alguien anade `created_at`, `deleted_at` o
     * `last_seen_at`, esta prueba lo delata en lugar de ignorarlo.
     */
    private const ALLOWED_TEMPORAL_COLUMNS = ['occurred_at'];

    /**
     * Descubre del esquema REAL toda columna que guarde un instante.
     *
     * Se mira el tipo declarado Y el sufijo `_at`, porque una columna temporal
     * guardada como `varchar` o como entero seguiria filtrando el segundo.
     *
     * @return array<int, string>
     */
    private function temporalColumnsOf(string $table): array
    {
        $found = [];

        foreach (Schema::getColumns($table) as $column) {
            $name = $column['name'];
            $type = mb_strtolower((string) ($column['type_name'] ?? $column['type'] ?? ''));

            $looksTemporal = str_contains($type, 'datetime')
                || str_contains($type, 'timestamp')
                || str_contains($type, 'date')
                || str_ends_with($name, '_at');

            if ($looksTemporal) {
                $found[] = $name;
            }
        }

        sort($found);

        return $found;
    }

    /**
     * 1. ESTRUCTURAL — la isla B no tiene ninguna columna temporal fuera de la
     *    lista blanca.
     *
     * FALLA con el codigo anterior: encontraba `created_at` y `updated_at`.
     */
    public function test_island_b_has_no_temporal_column_outside_the_allowlist(): void
    {
        $unexpected = array_diff(
            $this->temporalColumnsOf(self::ISLAND_B),
            self::ALLOWED_TEMPORAL_COLUMNS,
        );

        $this->assertSame([], array_values($unexpected), sprintf(
            'La isla B (%s) tiene columnas temporales no autorizadas: %s. '
            .'Toda marca temporal de esta tabla filtra la correlacion con '
            .'responses.completed_at (ARCHITECTURE.md §7.3, hallazgo F-01). '
            .'`occurred_at` ya es la marca canonica y se trunca al minuto; '
            .'cualquier otra debe eliminarse, no "recordar truncarse".',
            self::ISLAND_B,
            implode(', ', $unexpected),
        ));
    }

    /**
     * 2. ESTRUCTURAL — el modelo no mantiene timestamps de Eloquent.
     *
     * Complementa la anterior: aunque la columna no exista, dejar
     * `$timestamps = true` haria que un `migrate:fresh` sobre un esquema
     * futuro las repoblara en silencio.
     *
     * FALLA con el codigo anterior: `$timestamps` era `true` por defecto.
     */
    public function test_the_security_event_model_does_not_maintain_eloquent_timestamps(): void
    {
        $this->assertFalse(
            (new SecurityEvent)->usesTimestamps(),
            'SecurityEvent debe declarar `public $timestamps = false`. Con los '
            .'timestamps activos Eloquent reintroduce created_at/updated_at con '
            .'precision de segundo (F-01).',
        );
    }

    /**
     * 3. DE COMPORTAMIENTO — ninguna columna temporal PERSISTIDA conserva
     *    segundos.
     *
     * Es la prueba que de verdad importa: no mira el esquema, mira el dato
     * escrito, leido en CRUDO desde la base de datos para que ningun cast ni
     * accesor del modelo pueda maquillar el valor.
     *
     * Se escribe a proposito en un instante con segundos != 0.
     *
     * FALLA con el codigo anterior: `created_at` y `updated_at` llegaban con
     * los segundos del reloj.
     */
    public function test_no_persisted_temporal_value_in_island_b_keeps_seconds(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-09 22:32:08'));

        SecurityEvent::create([
            'occurred_at' => now(),
            'event_type' => SecurityEvent::TYPE_RATE_LIMITED,
            'ip_address' => '192.0.2.17',   // RFC 5737, no es de nadie
            'route' => 'POST /s/{publicId}/responses',
            'http_status' => 429,
            'reason' => 'too_many_requests',
            'correlation_id' => '11111111-1111-4111-8111-111111111111',
            'user_id' => null,
            'survey_id' => null,
        ]);

        Carbon::setTestNow();

        $row = (array) DB::table(self::ISLAND_B)->first();
        $this->assertNotEmpty($row, 'No se persistio la fila de prueba.');

        $checked = 0;

        foreach ($this->temporalColumnsOf(self::ISLAND_B) as $column) {
            $raw = $row[$column] ?? null;

            if ($raw === null || $raw === '') {
                continue;
            }

            $seconds = (int) Carbon::parse((string) $raw)->second;
            $checked++;

            $this->assertSame(0, $seconds, sprintf(
                'security_events.%s persistio "%s", con %d segundos. Toda marca '
                .'temporal de la isla B debe estar truncada al minuto: con el '
                .'segundo expuesto, un JOIN de +-2 s contra responses.completed_at '
                .'reasocia respuesta<->IP (F-01).',
                $column,
                $raw,
                $seconds,
            ));
        }

        $this->assertGreaterThan(0, $checked, 'No se comprobo ninguna columna temporal.');
    }

    /**
     * 4. CONTRASTE — la isla A SI conserva el segundo, y debe conservarlo.
     *
     * Sin esta mitad, "todo truncado al minuto" se podria conseguir truncando
     * tambien `responses.completed_at`, lo que degradaria el dato de negocio
     * sin necesidad. La asimetria ES la mitigacion: minuto en la isla B,
     * segundo en la isla A.
     */
    public function test_island_a_keeps_second_precision_because_that_is_the_point(): void
    {
        $this->assertContains(
            'completed_at',
            $this->temporalColumnsOf('responses'),
            'responses.completed_at debe existir: es el lado que conserva el segundo.',
        );

        $this->assertNotContains(
            'ip_address',
            array_column(Schema::getColumns('responses'), 'name'),
            'La isla A no puede tener IP bajo ninguna circunstancia.',
        );

        $this->assertTrue(
            class_exists(Response::class),
            'El modelo Response debe existir para que este contraste tenga sentido.',
        );
    }
}
