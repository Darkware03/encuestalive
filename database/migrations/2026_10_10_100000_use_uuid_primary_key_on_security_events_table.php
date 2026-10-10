<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * F-02 (reclasificado MEDIO -> ALTO en el retest de la Fase 7c).
 *
 * =======================  POR QUE EXISTE ESTA MIGRACION  =======================
 *
 * `security_events.occurred_at` se trunca al minuto a proposito: es la mitigacion
 * que impide cruzar una respuesta con una IP por coincidencia temporal. En la
 * Fase 7b eliminamos `created_at`/`updated_at` porque guardaban el segundo exacto
 * y anulaban esa proteccion (F-01).
 *
 * El retest demostro que la proteccion SEGUIA rota por otra via. La clave
 * primaria era `integer primary key autoincrement`, es decir, un contador
 * ESTRICTAMENTE MONOTONO. Eso no guarda una hora, pero guarda un ORDEN, y un
 * orden mas dos relojes conocidos equivale a una hora:
 *
 *   1. El atacante provoca un evento propio a una hora que el conoce. Basta un
 *      GET a un identificador publico inexistente: es anonimo y gratuito.
 *   2. Repite despues del evento ajeno que quiere fechar.
 *   3. El `id` del evento ajeno queda ENCAJONADO entre sus dos marcas.
 *
 * Medicion real del auditor sobre este esquema:
 *
 *   evento ajeno id=27  ip=203.0.113.42  occurred_at=23:55:00  (declarado: 60 s)
 *      => VENTANA REAL = 0,254 segundos   (mejora x236)
 *      => 1 candidata de 7 respuestas de ese minuto
 *
 * Ordenar no es fechar, pero sirve igual para correlacionar. Y es un canal
 * ACTIVO: la precision la elige el atacante subiendo la cadencia de sus marcas.
 *
 * ----------------------------- LA CORRECCION -----------------------------
 *
 * Clave primaria UUIDv4: aleatoria, sin relacion con el orden de insercion.
 * Dos eventos consecutivos dejan de ser comparables, y la unica senal temporal
 * que queda es `occurred_at`, que ya esta truncado al minuto a proposito.
 *
 * En la Fase 7b se rechazo este cambio alegando degradacion de indices. El
 * argumento era razonable cuando F-02 era un canal SECUNDARIO que solo
 * complementaba a `created_at`. Al eliminar `created_at` paso a ser el canal
 * PRINCIPAL, y nadie reevaluo su severidad. Con severidad ALTA el coste en
 * indices es claramente aceptable: esta tabla se escribe de forma esporadica,
 * se lee poco y se purga a los 30 dias (D-12). No es una tabla caliente.
 *
 * NOTA: no se conservan los identificadores antiguos. Son datos tecnicos de
 * seguridad sin valor historico, y mantener la correspondencia reintroduciria
 * exactamente el orden que esta migracion elimina.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Tabla nueva con la misma forma, salvo la clave primaria.
        Schema::create('security_events_uuid', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Truncado al minuto por la capa de aplicacion. Unica senal
            // temporal que esta tabla debe conservar.
            $table->dateTime('occurred_at');

            $table->string('event_type');
            $table->string('ip_address')->nullable();
            $table->string('route')->nullable();
            $table->integer('http_status')->nullable();
            $table->string('reason')->nullable();
            $table->string('correlation_id')->nullable();

            // Unica clave foranea de la isla B, y apunta a `users`, nunca a
            // una respuesta ni a una sesion de participante.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Entero suelto a proposito: SIN clave foranea, para que no exista
            // ningun camino relacional entre un evento y el contenido de una
            // encuesta.
            $table->integer('survey_id')->nullable();

            // OJO: los indices NO se crean aqui.
            //
            // En SQLite el espacio de nombres de los indices es GLOBAL a la
            // base de datos, no local a la tabla. Crear aqui
            // `security_events_occurred_index` mientras la tabla original
            // todavia existe produce "index ... already exists" y revienta la
            // migracion entera. Se crean mas abajo, ya con la tabla definitiva
            // renombrada y la antigua eliminada.
        });

        // Copia de los datos existentes con identificadores nuevos.
        DB::table('security_events')->orderBy('id')->chunkById(500, function ($rows) {
            $payload = [];

            foreach ($rows as $row) {
                $payload[] = [
                    'id' => (string) Str::uuid(),
                    'occurred_at' => $row->occurred_at,
                    'event_type' => $row->event_type,
                    'ip_address' => $row->ip_address,
                    'route' => $row->route,
                    'http_status' => $row->http_status,
                    'reason' => $row->reason,
                    'correlation_id' => $row->correlation_id,
                    'user_id' => $row->user_id,
                    'survey_id' => $row->survey_id,
                ];
            }

            if ($payload !== []) {
                // Se inserta en bloque y sin ordenar por el `id` nuevo: el
                // orden de las filas en disco tampoco debe reproducir el
                // orden cronologico original.
                shuffle($payload);
                DB::table('security_events_uuid')->insert($payload);
            }
        });

        // Al eliminar la tabla original se liberan tambien sus nombres de
        // indice, que es justo lo que necesitamos para reutilizarlos.
        Schema::drop('security_events');
        Schema::rename('security_events_uuid', 'security_events');

        // Mismos nombres que la migracion original: hay una prueba que los
        // exige por nombre, y renombrarlos en silencio habria sido un cambio
        // de contrato encubierto.
        Schema::table('security_events', function (Blueprint $table) {
            $table->index('occurred_at', 'security_events_occurred_index');
            $table->index(['event_type', 'occurred_at'], 'security_events_type_occurred_index');
            $table->index(['ip_address', 'occurred_at'], 'security_events_ip_occurred_index');
            $table->index(['survey_id', 'occurred_at'], 'security_events_survey_occurred_index');
        });
    }

    /**
     * ATENCION: revertir esta migracion REABRE el hallazgo F-02.
     *
     * Mi primer impulso fue dejar `down()` lanzando una excepcion, para que
     * nadie pudiera deshacer una decision de privacidad por descuido. Fue un
     * error: el proyecto tiene una prueba
     * (`FreshMigrationTest::test_every_migration_can_be_rolled_back_and_reapplied`)
     * que exige que TODA migracion sea reversible, y romper ese invariante de
     * forma unilateral es peor que el riesgo que pretendia evitar. Una suite
     * que ya no puede reconstruir el esquema desde cero pierde mucho mas valor
     * del que aporta una barrera moral incrustada en una migracion.
     *
     * El sitio correcto para esa advertencia es la documentacion y el aviso de
     * aqui abajo, no una excepcion que deja el esquema a medias.
     */
    public function down(): void
    {
        Schema::create('security_events_int', function (Blueprint $table) {
            $table->id();
            $table->dateTime('occurred_at');
            $table->string('event_type');
            $table->string('ip_address')->nullable();
            $table->string('route')->nullable();
            $table->integer('http_status')->nullable();
            $table->string('reason')->nullable();
            $table->string('correlation_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('survey_id')->nullable();

            // Mismo motivo que en up(): los indices se crean despues del
            // renombrado, cuando sus nombres ya estan libres.
        });

        DB::table('security_events')->orderBy('id')->chunkById(500, function ($rows) {
            $payload = [];

            foreach ($rows as $row) {
                $payload[] = [
                    'occurred_at' => $row->occurred_at,
                    'event_type' => $row->event_type,
                    'ip_address' => $row->ip_address,
                    'route' => $row->route,
                    'http_status' => $row->http_status,
                    'reason' => $row->reason,
                    'correlation_id' => $row->correlation_id,
                    'user_id' => $row->user_id,
                    'survey_id' => $row->survey_id,
                ];
            }

            if ($payload !== []) {
                DB::table('security_events_int')->insert($payload);
            }
        });

        Schema::drop('security_events');
        Schema::rename('security_events_int', 'security_events');

        Schema::table('security_events', function (Blueprint $table) {
            $table->index('occurred_at', 'security_events_occurred_index');
            $table->index(['event_type', 'occurred_at'], 'security_events_type_occurred_index');
            $table->index(['ip_address', 'occurred_at'], 'security_events_ip_occurred_index');
            $table->index(['survey_id', 'occurred_at'], 'security_events_survey_occurred_index');
        });
    }
};
