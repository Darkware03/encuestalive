<?php

namespace Tests\Feature\Database;

use App\Models\SecurityEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * F-02 — Ausencia de canales de ordenacion temporal en la isla de seguridad.
 *
 * ======================= POR QUE ESTE FICHERO EXISTE =======================
 *
 * `security_events` guarda direcciones IP. `occurred_at` se trunca al minuto a
 * proposito, para que nadie pueda cruzar una respuesta con una IP por
 * coincidencia temporal. Esa mitigacion se rompio TRES veces seguidas, y las
 * tres veces la suite siguio en verde:
 *
 *   1. `created_at` guardaba el SEGUNDO.
 *   2. La clave `autoincrement` era un contador monotono: sembrando marcas
 *      propias se acotaba la hora de un evento ajeno a 0,254 s.
 *   3. La clave paso a `HasUuids`, que en Laravel 13.35.0 genera **UUIDv7**:
 *      48 bits de marca de tiempo en MILISEGUNDOS y en claro. Y el `rowid`
 *      implicito de SQLite seguia ahi, acotando a 0,243 s.
 *
 * Las pruebas anteriores comprobaban la FORMA ("no existe una columna llamada
 * `created_at`", "la clave no es autoincremental"). Por eso no detectaron
 * nada: cada arreglo cambiaba la forma y dejaba intacta la propiedad.
 *
 * Este fichero comprueba la PROPIEDAD, que es la que el brief §5.2 promete a
 * los participantes:
 *
 *   >  Dadas dos filas de `security_events`, no debe existir ningun valor
 *   >  legible de la tabla que permita deducir cual se escribio antes, ni
 *   >  recuperar su instante con mas precision que el minuto declarado.
 *
 * Si alguien vuelve a introducir un canal de ordenacion, da igual la forma que
 * adopte: estas pruebas deben caerse.
 */
class SecurityEventOrderingTest extends TestCase
{
    use RefreshDatabase;

    /** Crea eventos separados en el tiempo para que cualquier canal temporal se note. */
    private function seedSpacedEvents(int $count = 6): array
    {
        $created = [];

        for ($i = 0; $i < $count; $i++) {
            $created[] = SecurityEvent::create([
                'occurred_at' => now()->startOfMinute(),
                'event_type' => 'test.marker',
                'ip_address' => '203.0.113.'.(10 + $i),
                'route' => '/s/prueba',
                'http_status' => 404,
                'reason' => 'marcador de prueba',
                'correlation_id' => bin2hex(random_bytes(8)),
            ]);

            usleep(20_000); // 20 ms: suficiente para que v7 los distinga
        }

        return $created;
    }

    /**
     * LA PRUEBA CENTRAL: la clave primaria no debe codificar un instante.
     *
     * No comprueba "que sea v4" sino algo mas fuerte y menos eludible: que de
     * los identificadores no se pueda extraer el orden de creacion.
     */
    public function test_the_primary_key_does_not_encode_the_creation_instant(): void
    {
        $events = $this->seedSpacedEvents();
        $ids = array_map(fn ($e) => (string) $e->getKey(), $events);

        // 1. Version del UUID: v7 lleva la hora dentro; v4 es aleatorio.
        foreach ($ids as $id) {
            $version = substr(str_replace('-', '', $id), 12, 1);

            $this->assertSame(
                '4',
                $version,
                "El identificador {$id} es UUIDv{$version}. UUIDv7 incrusta 48 bits de marca "
                .'de tiempo en milisegundos EN CLARO, lo que permite fechar un evento de '
                .'seguridad con precision de milisegundo y reasociarlo con una respuesta. '
                .'Usa v4 (aleatorio).'
            );
        }

        // 2. Los primeros 48 bits NO deben corresponder a una hora plausible.
        //    Es la comprobacion que caza un v7 aunque alguien falsee el nibble.
        foreach ($ids as $id) {
            $milliseconds = (int) hexdec(substr(str_replace('-', '', $id), 0, 12));
            $asSeconds = (int) ($milliseconds / 1000);

            $this->assertFalse(
                $asSeconds > now()->subYear()->timestamp && $asSeconds < now()->addYear()->timestamp,
                "Los primeros 48 bits de {$id} se decodifican como "
                .date('Y-m-d H:i:s', $asSeconds).', una fecha plausible. '
                .'La clave primaria esta filtrando el instante de creacion.'
            );
        }

        // 3. El orden lexicografico no debe reproducir el de creacion.
        $sorted = $ids;
        sort($sorted);

        $this->assertNotSame(
            $ids,
            $sorted,
            'Los identificadores, ordenados alfabeticamente, reproducen el orden de creacion. '
            .'Un ORDER BY id revela la cronologia aunque las fechas esten truncadas.'
        );
    }

    /**
     * El `rowid` implicito de SQLite es un contador monotono consultable.
     *
     * No basta con que la clave visible sea aleatoria: SQLite mantiene un
     * `rowid` entero por debajo en toda tabla que no se declare
     * `WITHOUT ROWID`, y `SELECT rowid FROM security_events` lo expone.
     */
    public function test_the_table_exposes_no_implicit_monotonic_counter(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('El rowid implicito es especifico de SQLite.');
        }

        $this->seedSpacedEvents(3);

        $exposed = true;

        try {
            DB::select('SELECT rowid FROM security_events LIMIT 1');
        } catch (\Throwable) {
            $exposed = false;
        }

        $this->assertFalse(
            $exposed,
            'La tabla expone un `rowid` consultable. Es un contador monotono asignado en el '
            .'orden de INSERT: permite acotar la hora de un evento ajeno sembrando marcas '
            .'propias, con independencia de lo aleatoria que sea la clave primaria. '
            .'Declara la tabla WITHOUT ROWID.'
        );
    }

    /** La declaracion WITHOUT ROWID debe constar en el esquema, no solo de palabra. */
    public function test_the_schema_declares_without_rowid(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Clausula especifica de SQLite.');
        }

        $sql = DB::selectOne(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'security_events'"
        )->sql;

        $this->assertStringContainsStringIgnoringCase(
            'WITHOUT ROWID',
            $sql,
            'security_events no se declara WITHOUT ROWID, de modo que conserva el contador '
            .'monotono interno del hallazgo F-02.'
        );
    }

    /** Ninguna columna puede guardar mas precision que el minuto declarado. */
    public function test_no_column_stores_more_precision_than_the_declared_minute(): void
    {
        $this->seedSpacedEvents(3);

        foreach (DB::table('security_events')->get() as $row) {
            foreach ((array) $row as $column => $value) {
                if (! is_string($value) || $value === '') {
                    continue;
                }

                $this->assertDoesNotMatchRegularExpression(
                    '/\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:(?!00)\d{2}/',
                    $value,
                    "La columna `{$column}` contiene un valor temporal con segundos distintos "
                    ."de cero: {$value}. La isla de seguridad solo puede conservar precision "
                    .'de minuto.'
                );
            }
        }
    }
}
