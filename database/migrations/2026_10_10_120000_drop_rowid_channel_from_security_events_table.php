<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * F-02, TERCER INTENTO. Cierra los canales de ordenacion que los dos anteriores
 * dejaron abiertos.
 *
 * ========================== HISTORIAL DEL FALLO ==========================
 *
 * 1. F-01: `occurred_at` se truncaba al minuto, pero `created_at` guardaba el
 *    SEGUNDO. Se eliminaron las columnas de timestamps.
 * 2. F-02 (1er intento): la clave `integer autoincrement` seguia siendo un
 *    contador monotono. Se cambio a `HasUuids`... que en Laravel 13.35.0
 *    genera **UUIDv7**, con 48 bits de marca de tiempo en MILISEGUNDOS y en
 *    claro. Se empeoro: de un ataque activo y ruidoso a uno pasivo y mudo.
 * 3. Este intento cierra las TRES vias que quedaban.
 *
 * ------------------------------ LAS TRES VIAS ------------------------------
 *
 * (a) **Identificador decodificable.** Resuelto en el modelo:
 *     `SecurityEvent::newUniqueId()` devuelve ahora `Str::uuid()`, v4 real y
 *     aleatoria. Esta migracion ademas **reescribe los identificadores ya
 *     almacenados**, porque cualquier fila creada por el modelo desde el
 *     intento anterior lleva un v7 con su hora dentro.
 *
 * (b) **`rowid` implicito.** SQLite mantiene un `rowid` entero y monotono en
 *     toda tabla que no se declare `WITHOUT ROWID`, y es consultable:
 *     `SELECT rowid FROM security_events`. El contador no desaparecio al
 *     dejar de llamarse `id`; solo dejo de verse. El auditor reejecuto el
 *     acotamiento y obtuvo **0,243 s**. Por eso aqui la tabla se recrea como
 *     **WITHOUT ROWID**.
 *
 *     El `shuffle()` del intento anterior era inutil: SQLite asigna el
 *     `rowid` en el orden del `INSERT`, sin importar como se barajen los
 *     datos antes.
 *
 * (c) **`ORDER BY id`.** Era consecuencia de (a): v7 es lexicograficamente
 *     ordenable por tiempo. Con v4 deja de serlo.
 *
 * ------------------------------ MySQL 8 ------------------------------
 *
 * `WITHOUT ROWID` es exclusivo de SQLite. En InnoDB no hace falta: el indice
 * agrupado se construye sobre la clave primaria, y con una clave v4 aleatoria
 * no existe orden temporal que extraer. Por eso la recreacion se aplica solo
 * en SQLite; en MySQL basta con reescribir los identificadores v7 a v4.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'id', 'occurred_at', 'event_type', 'ip_address', 'route',
        'http_status', 'reason', 'correlation_id', 'user_id', 'survey_id',
    ];

    public function up(): void
    {
        $rows = DB::table('security_events')->get();

        // Identificadores nuevos v4 para TODA fila existente: las migradas y,
        // sobre todo, las que creo el modelo con v7.
        $payload = [];

        foreach ($rows as $row) {
            $record = ['id' => (string) Str::uuid()];

            foreach (self::COLUMNS as $column) {
                if ($column !== 'id') {
                    $record[$column] = $row->{$column};
                }
            }

            $payload[] = $record;
        }

        if (DB::connection()->getDriverName() !== 'sqlite') {
            // MySQL/PostgreSQL: basta reescribir los identificadores.
            foreach ($rows as $index => $row) {
                DB::table('security_events')
                    ->where('id', $row->id)
                    ->update(['id' => $payload[$index]['id']]);
            }

            return;
        }

        // SQLite: hay que recrear la tabla para declararla WITHOUT ROWID.
        // Se usa SQL explicito porque el constructor de esquemas de Laravel no
        // expone esa clausula.
        DB::statement('DROP TABLE IF EXISTS security_events_norowid');

        DB::statement(<<<'SQL'
            CREATE TABLE security_events_norowid (
                id              VARCHAR NOT NULL,
                occurred_at     DATETIME NOT NULL,
                event_type      VARCHAR NOT NULL,
                ip_address      VARCHAR,
                route           VARCHAR,
                http_status     INTEGER,
                reason          VARCHAR,
                correlation_id  VARCHAR,
                user_id         INTEGER REFERENCES users(id) ON DELETE SET NULL,
                survey_id       INTEGER,
                PRIMARY KEY (id)
            ) WITHOUT ROWID
        SQL);

        foreach (array_chunk($payload, 500) as $chunk) {
            DB::table('security_events_norowid')->insert($chunk);
        }

        Schema::drop('security_events');
        DB::statement('ALTER TABLE security_events_norowid RENAME TO security_events');

        // Los indices se crean al final, cuando sus nombres estan libres: en
        // SQLite el espacio de nombres de indices es global a la base de datos.
        DB::statement('CREATE INDEX security_events_occurred_index ON security_events (occurred_at)');
        DB::statement('CREATE INDEX security_events_type_occurred_index ON security_events (event_type, occurred_at)');
        DB::statement('CREATE INDEX security_events_ip_occurred_index ON security_events (ip_address, occurred_at)');
        DB::statement('CREATE INDEX security_events_survey_occurred_index ON security_events (survey_id, occurred_at)');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        // Reversion: tabla equivalente CON rowid. Reabre la via (b) del
        // hallazgo F-02; existe solo para cumplir el invariante del proyecto
        // de que toda migracion sea reversible.
        $rows = DB::table('security_events')->get();

        DB::statement('DROP TABLE IF EXISTS security_events_rowid');

        DB::statement(<<<'SQL'
            CREATE TABLE security_events_rowid (
                id              VARCHAR NOT NULL,
                occurred_at     DATETIME NOT NULL,
                event_type      VARCHAR NOT NULL,
                ip_address      VARCHAR,
                route           VARCHAR,
                http_status     INTEGER,
                reason          VARCHAR,
                correlation_id  VARCHAR,
                user_id         INTEGER REFERENCES users(id) ON DELETE SET NULL,
                survey_id       INTEGER,
                PRIMARY KEY (id)
            )
        SQL);

        $payload = [];

        foreach ($rows as $row) {
            $record = [];

            foreach (self::COLUMNS as $column) {
                $record[$column] = $row->{$column};
            }

            $payload[] = $record;
        }

        foreach (array_chunk($payload, 500) as $chunk) {
            DB::table('security_events_rowid')->insert($chunk);
        }

        Schema::drop('security_events');
        DB::statement('ALTER TABLE security_events_rowid RENAME TO security_events');

        DB::statement('CREATE INDEX security_events_occurred_index ON security_events (occurred_at)');
        DB::statement('CREATE INDEX security_events_type_occurred_index ON security_events (event_type, occurred_at)');
        DB::statement('CREATE INDEX security_events_ip_occurred_index ON security_events (ip_address, occurred_at)');
        DB::statement('CREATE INDEX security_events_survey_occurred_index ON security_events (survey_id, occurred_at)');
    }
};
