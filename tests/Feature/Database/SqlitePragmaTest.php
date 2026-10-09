<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * D-04 / ADR-0003: los PRAGMA de SQLite deben venir de config/database.php, no
 * de una migracion, para que se apliquen a CADA conexion nueva.
 */
class SqlitePragmaTest extends TestCase
{
    private function skipUnlessSqlite(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Prueba especifica de SQLite.');
        }
    }

    public function test_foreign_keys_pragma_is_on(): void
    {
        $this->skipUnlessSqlite();

        // El hallazgo critico de ARCHITECTURE.md V14: SQLite entrega esto en 0.
        $value = DB::select('PRAGMA foreign_keys')[0]->foreign_keys;

        $this->assertSame(1, (int) $value, 'PRAGMA foreign_keys debe estar en 1 (ON).');
    }

    public function test_busy_timeout_is_configured(): void
    {
        $this->skipUnlessSqlite();

        $this->assertSame(5000, (int) config('database.connections.sqlite.busy_timeout'));
        $this->assertGreaterThan(0, (int) DB::select('PRAGMA busy_timeout')[0]->timeout);
    }

    public function test_journal_mode_is_wal_in_config(): void
    {
        $this->skipUnlessSqlite();

        $this->assertSame('WAL', config('database.connections.sqlite.journal_mode'));

        // En `:memory:` SQLite NO puede usar WAL y responde `memory`. No es un
        // fallo de configuracion: es una limitacion del almacenamiento en memoria.
        // La suite corre en :memory: (phpunit.xml), asi que solo se exige `wal`
        // cuando la base es un fichero real.
        $mode = strtolower(DB::select('PRAGMA journal_mode')[0]->journal_mode);
        $database = config('database.connections.sqlite.database');

        if ($database === ':memory:' || $database === '') {
            $this->assertSame('memory', $mode);
        } else {
            $this->assertSame('wal', $mode);
        }
    }
}
