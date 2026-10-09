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
     * HALLAZGO CERRADO EN LA FASE 7b (F-H1). Antes valia `['sessions']`.
     *
     * `sessions` es la tabla DEL FRAMEWORK que usa el driver de sesion de
     * Laravel, no una tabla del modelo de EncuestaLive. De fabrica traia
     * `ip_address` y `user_agent`, de modo que con SESSION_DRIVER=database
     * habria guardado la IP de CUALQUIER visitante, participantes anonimos
     * incluidos: un SEGUNDO almacen de direcciones IP.
     *
     * La Fase 4 lo reporto y D-11 lo mitigo fijando SESSION_DRIVER=file. Pero
     * esa mitigacion dependia de UNA VARIABLE DE ENTORNO, y `database` es
     * ademas el valor por defecto de config/session.php: un operador podia
     * reintroducir el almacen sin sospecharlo y sin que nada fallara.
     *
     * La Fase 7b ELIMINA las dos columnas de la migracion. La propiedad deja
     * de depender de la configuracion y pasa a ser estructural, que es el
     * mismo criterio aplicado a la isla A: el dato no se guarda porque no hay
     * donde guardarlo. AppServiceProvider avisa ademas si alguien configura el
     * driver `database`, para que el fallo explique la regla en vez de dar un
     * error SQL criptico.
     *
     * Esta lista VACIA es la afirmacion fuerte: `ip_address` existe en
     * EXACTAMENTE una tabla de todo el esquema, `security_events`.
     */
    private const FRAMEWORK_TABLES_WITH_IP = [];

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
            'Ninguna tabla del esquema, salvo security_events, puede almacenar '
            .'direcciones IP. Desde la Fase 7b esto incluye a `sessions`, la tabla del '
            .'framework: sus columnas ip_address/user_agent se eliminaron para que la '
            .'regla no dependa de SESSION_DRIVER (hallazgo F-H1).'
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

        /*
         * ACTUALIZADO EN LA FASE 7b (hallazgo F-01).
         *
         * Esta lista permitida incluia `created_at` y `updated_at`, porque la
         * isla B los tenia. Eran precisamente el defecto: en la misma fila
         * cuyo `occurred_at` se trunca al minuto, Eloquent escribia la marca
         * al SEGUNDO, y un JOIN por ventana de +-2 s contra
         * `responses.completed_at` devolvia candidatos respuesta<->IP.
         *
         * Al eliminarlos (migracion 2026_10_10_090000), la interseccion se
         * REDUCE: la propiedad de privacidad es ahora estrictamente mas
         * fuerte, no mas debil. La prueba fallaba por tener la lista vieja.
         *
         * Queda compartido `id`, que es el canal lateral de ordenacion que el
         * informe documenta como F-02 y que se acepta como riesgo residual:
         * eliminarlo exigiria una clave primaria UUIDv4 y degradaria los
         * indices. El control que de verdad lo acota es la purga de 30 dias
         * (D-12), ya programada tras corregir F-13.
         */
        foreach (['responses', 'response_answers', 'response_selections', 'participant_sessions'] as $table) {
            $shared = array_values(array_intersect(Schema::getColumnListing($table), $securityColumns));

            sort($shared);
            $allowed = ['id'];
            if ($table === 'responses' || $table === 'participant_sessions') {
                $allowed[] = 'survey_id';   // entero suelto en la isla B, sin FK
                sort($allowed);
            }

            $this->assertSame(
                $allowed,
                $shared,
                "Cambio el conjunto de columnas compartidas entre {$table} y security_events."
            );

            /*
             * Y lo que de verdad cierra F-01: NINGUNA columna temporal puede
             * ser comun a las dos islas. Mientras lo fuera, existiria un JOIN
             * por tiempo que escribir.
             */
            $sharedTemporal = array_values(array_filter(
                $shared,
                fn (string $column): bool => str_ends_with($column, '_at'),
            ));

            $this->assertSame([], $sharedTemporal, sprintf(
                '%s y security_events comparten columnas temporales (%s). Eso permite '
                .'un JOIN por ventana de tiempo entre la isla A y la unica tabla con '
                .'direcciones IP (F-01).',
                $table,
                implode(', ', $sharedTemporal),
            ));
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
