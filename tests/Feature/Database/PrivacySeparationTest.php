<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * BRIEF §5.2 / ARCHITECTURE.md §7 — SEPARACION FISICA RESPUESTA <-> IP.
 *
 * Requisito duro de la Fase 4: "NO debe existir ninguna clave foranea ni columna
 * que permita unir una respuesta con una IP. Demuestralo."
 *
 * La demostracion no puede ser "no hacemos esa consulta": tiene que ser que la
 * consulta sea IMPOSIBLE DE ESCRIBIR. Estas pruebas se ejecutan contra el esquema
 * REAL creado por las migraciones, no contra la documentacion.
 */
class PrivacySeparationTest extends TestCase
{
    use RefreshDatabase;

    /** Isla A — datos de participacion. */
    private const ISLAND_A = [
        'surveys',
        'questions',
        'question_options',
        'participant_sessions',
        'responses',
        'response_answers',
        'response_selections',
        'admin_audit_events',
    ];

    /** Isla B — registros tecnicos de seguridad. */
    private const ISLAND_B = ['security_events'];

    /**
     * HALLAZGO DECLARADO (Fase 4). `sessions` es la tabla DEL FRAMEWORK que usa
     * el driver de sesion de Laravel, no una tabla del modelo de EncuestaLive.
     * Con SESSION_DRIVER=database (valor actual de .env y .env.example) guarda
     * `ip_address` y `user_agent` de CUALQUIER visitante con cookie de sesion,
     * incluidos los participantes anonimos.
     *
     * No crea un puente respuesta<->IP: `sessions.id` es el identificador de
     * sesion de Laravel, no tiene relacion alguna con `participant_sessions`,
     * y no existe columna compartida por la que unirlas (lo verifica
     * test_framework_sessions_table_cannot_be_joined_to_participation_data).
     * Pero SI es un segundo almacen de IP que ARCHITECTURE.md §7 no contemplo.
     *
     * Mitigacion recomendada, FUERA del ambito de escritura de la Fase 4:
     * SESSION_DRIVER=file (o cookie) en .env y .env.example. Reportado a
     * darkware y a devops-release; ver DATABASE.md §9.
     */
    private const FRAMEWORK_TABLES_WITH_IP = ['sessions'];

    /** Cualquier nombre de columna que huela a identificador de red o dispositivo. */
    private const NETWORK_COLUMN_PATTERNS = [
        'ip', 'ip_address', 'remote_addr', 'client_ip', 'x_forwarded_for',
        'user_agent', 'useragent', 'ua', 'device', 'fingerprint',
        'referer', 'referrer', 'accept_language', 'language_header',
        'timezone', 'screen_resolution', 'geo', 'latitude', 'longitude',
    ];

    /**
     * NINGUNA tabla de la isla A tiene columna de red o de dispositivo.
     * Si manana alguien anade `ip_address` a `participant_sessions`, esto falla.
     */
    public function test_island_a_has_no_network_or_device_columns(): void
    {
        foreach (self::ISLAND_A as $table) {
            $this->assertTrue(Schema::hasTable($table), "Falta la tabla {$table}.");

            foreach (Schema::getColumnListing($table) as $column) {
                $normalized = strtolower($column);

                $this->assertNotContains(
                    $normalized,
                    self::NETWORK_COLUMN_PATTERNS,
                    "La tabla de participacion {$table} no puede tener la columna {$column}."
                );

                $this->assertStringNotContainsString(
                    'ip_address',
                    $normalized,
                    "La tabla de participacion {$table} no puede contener direcciones IP ({$column})."
                );
                $this->assertStringNotContainsString(
                    'user_agent',
                    $normalized,
                    "La tabla de participacion {$table} no puede contener user-agent ({$column})."
                );
            }
        }
    }

    /** La IP existe en EXACTAMENTE una tabla del esquema completo. */
    public function test_ip_address_lives_in_exactly_one_table(): void
    {
        $tablesWithIp = [];

        foreach (Schema::getTables() as $table) {
            $name = $table['name'];
            if (str_starts_with($name, 'sqlite_') || $name === 'migrations') {
                continue;
            }
            foreach (Schema::getColumnListing($name) as $column) {
                if (str_contains(strtolower($column), 'ip_address')) {
                    $tablesWithIp[] = $name;
                }
            }
        }

        $expected = array_merge(self::ISLAND_B, self::FRAMEWORK_TABLES_WITH_IP);
        sort($expected);

        $actual = array_values(array_unique($tablesWithIp));
        sort($actual);

        $this->assertSame(
            $expected,
            $actual,
            'Ninguna tabla del modelo de EncuestaLive, salvo security_events, puede '
            .'almacenar direcciones IP. `sessions` es la tabla del framework y es un '
            .'hallazgo declarado (ver DATABASE.md §9), no una tabla de participacion.'
        );
    }

    /**
     * El hallazgo anterior no debe degradarse en un puente real: se comprueba
     * que `sessions` no comparte NINGUNA columna de union con la cadena de
     * participacion mas alla de las genericas del framework.
     */
    public function test_framework_sessions_table_cannot_be_joined_to_participation_data(): void
    {
        $forbidden = [
            'participant_session_id',
            'response_id',
            'response_answer_id',
            'question_id',
            'question_option_id',
            'survey_id',
            'token_hash',
            'public_id',
        ];

        $columns = array_map('strtolower', Schema::getColumnListing('sessions'));

        foreach ($forbidden as $column) {
            $this->assertNotContains(
                $column,
                $columns,
                "La tabla de sesiones del framework no puede tener {$column}."
            );
        }

        foreach (['responses', 'response_answers', 'response_selections', 'participant_sessions'] as $table) {
            $shared = array_values(array_intersect(Schema::getColumnListing($table), $columns));
            sort($shared);

            $this->assertSame(
                ['id'],
                $shared,
                "Aparecio una columna compartida entre {$table} y la tabla `sessions` del framework."
            );
        }

        // `sessions.id` es una cadena (identificador de sesion de Laravel) y
        // `participant_sessions.id` / `responses.id` son enteros autoincrementales:
        // unirlas por `id` no produce ninguna correspondencia semantica.
        // SQLite informa `varchar`; MySQL 8 informaria `varchar` tambien.
        $this->assertStringContainsString(
            'char',
            strtolower(Schema::getColumnType('sessions', 'id')),
            'sessions.id debe seguir siendo textual, no un entero unible a la isla A.'
        );
    }

    /**
     * La isla B no guarda NINGUNA referencia a la isla A de participacion.
     * Sin estas columnas, el JOIN respuesta<->IP no tiene por donde escribirse.
     */
    public function test_security_events_cannot_reference_participation_data(): void
    {
        $forbidden = [
            'participant_session_id',
            'session_id',
            'response_id',
            'response_answer_id',
            'response_selection_id',
            'question_id',
            'question_option_id',
            'token',
            'token_hash',
            'answer',
            'text_answer',
        ];

        $columns = array_map('strtolower', Schema::getColumnListing('security_events'));

        foreach ($forbidden as $column) {
            $this->assertNotContains(
                $column,
                $columns,
                "security_events no puede tener la columna {$column}: crearia el puente entre islas."
            );
        }
    }

    /**
     * Ninguna clave foranea cruza de la isla B a la isla A.
     * Se lee del esquema efectivo, con `Schema::getForeignKeys()`.
     */
    public function test_no_foreign_key_bridges_the_two_islands(): void
    {
        $foreignKeys = Schema::getForeignKeys('security_events');

        foreach ($foreignKeys as $fk) {
            $this->assertNotContains(
                $fk['foreign_table'],
                self::ISLAND_A,
                "security_events no puede tener una FK hacia {$fk['foreign_table']}."
            );
            // La unica FK permitida es hacia `users` (administradores conocidos).
            $this->assertSame('users', $fk['foreign_table']);
        }
    }

    /**
     * Comprobacion simetrica: ninguna tabla de la isla A referencia a security_events.
     */
    public function test_island_a_never_references_security_events(): void
    {
        foreach (self::ISLAND_A as $table) {
            foreach (Schema::getForeignKeys($table) as $fk) {
                $this->assertNotContains(
                    $fk['foreign_table'],
                    self::ISLAND_B,
                    "{$table} no puede referenciar a {$fk['foreign_table']}."
                );
            }
        }
    }

    /**
     * Demostracion operativa: con datos reales en ambas islas, no existe ninguna
     * pareja de columnas por la que unirlas. Se calcula la interseccion de los
     * nombres de columna de la cadena respuesta->...->sesion con los de
     * security_events; solo pueden coincidir columnas tecnicas genericas
     * (id, created_at, updated_at) y `survey_id`, que es un entero SUELTO.
     */
    public function test_there_is_no_joinable_column_between_a_response_and_an_ip(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $this->assertGreaterThan(0, DB::table('responses')->count());
        $this->assertGreaterThan(0, DB::table('security_events')->whereNotNull('ip_address')->count());

        $securityColumns = Schema::getColumnListing('security_events');

        foreach (['responses', 'response_answers', 'response_selections', 'participant_sessions'] as $table) {
            $shared = array_values(array_intersect(Schema::getColumnListing($table), $securityColumns));

            sort($shared);
            $allowed = ['created_at', 'id', 'updated_at'];
            if ($table === 'responses' || $table === 'participant_sessions') {
                $allowed[] = 'survey_id';   // entero suelto en la isla B, sin FK
                sort($allowed);
            }

            $this->assertSame(
                $allowed,
                $shared,
                "Aparecio una columna nueva compartida entre {$table} y security_events."
            );
        }

        // `survey_id` permite saber que hubo abuso CONTRA una encuesta, nunca
        // QUIEN respondio: no hay sesion, ni respuesta, ni token al otro lado.
        // Riesgo residual de correlacion temporal declarado en ARCHITECTURE §7.3
        // y mitigado truncando security_events.occurred_at al minuto.
        $this->assertTrue(
            collect(Schema::getForeignKeys('security_events'))
                ->pluck('columns')
                ->flatten()
                ->doesntContain('survey_id'),
            'security_events.survey_id debe ser un entero suelto, sin clave foranea.'
        );
    }

    /** El token en claro no se guarda nunca: en BD solo vive su SHA-256. */
    public function test_participant_sessions_store_only_a_hash(): void
    {
        $columns = array_map('strtolower', Schema::getColumnListing('participant_sessions'));

        $this->assertContains('token_hash', $columns);
        $this->assertNotContains('token', $columns);
        $this->assertNotContains('plain_token', $columns);
    }
}
