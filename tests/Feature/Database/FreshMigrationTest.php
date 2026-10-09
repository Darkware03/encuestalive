<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * BRIEF §8: "Todas las migraciones deben probarse desde una base de datos vacia."
 * ADR-0003, encargo 3: ademas, cada `down()` debe ser funcional.
 */
class FreshMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED_TABLES = [
        'users',
        'surveys',
        'questions',
        'question_options',
        'participant_sessions',
        'responses',
        'response_answers',
        'response_selections',
        'admin_audit_events',
        'security_events',
    ];

    /** RefreshDatabase ya migra desde vacio; aqui se verifica el resultado. */
    public function test_all_tables_exist_after_a_clean_migration(): void
    {
        foreach (self::EXPECTED_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "Falta la tabla {$table}.");
        }
    }

    public function test_every_migration_can_be_rolled_back_and_reapplied(): void
    {
        $exit = Artisan::call('migrate:rollback', ['--force' => true]);
        $this->assertSame(0, $exit, Artisan::output());

        foreach (['surveys', 'security_events', 'responses'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "down() no elimino {$table}.");
        }

        $exit = Artisan::call('migrate', ['--force' => true]);
        $this->assertSame(0, $exit, Artisan::output());

        foreach (self::EXPECTED_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "Falta {$table} tras re-migrar.");
        }
    }

    /**
     * Indices exigidos por ADR-0003/ADR-0004 y ARCHITECTURE §14. Se leen del
     * esquema efectivo: si alguien borra un indice, esta prueba cae.
     */
    public function test_required_indexes_exist(): void
    {
        $expected = [
            'surveys' => ['surveys_public_id_unique', 'surveys_status_created_at_index'],
            'participant_sessions' => [
                'participant_sessions_survey_token_unique',
                'participant_sessions_survey_last_seen_index',
                'participant_sessions_survey_state_index',
            ],
            'responses' => [
                'responses_session_unique',
                'responses_survey_submission_uuid_unique',
                'responses_survey_completed_at_index',
            ],
            'response_answers' => ['response_answers_response_question_unique'],
            'response_selections' => ['response_selections_option_index'],
            'security_events' => ['security_events_occurred_index'],
        ];

        foreach ($expected as $table => $indexes) {
            $actual = array_map(
                fn (array $index) => $index['name'],
                Schema::getIndexes($table)
            );

            foreach ($indexes as $index) {
                $this->assertContains($index, $actual, "Falta el indice {$index} en {$table}.");
            }
        }
    }

    /**
     * ADR-0003, verificacion 3: revision estatica de portabilidad.
     * Si aparece SQL especifico de un motor, falla aqui y no en MySQL 8.
     *
     * Se analiza el CODIGO, no los comentarios: las reglas de portabilidad se
     * documentan nombrando las funciones prohibidas, y un `grep` crudo se
     * delataria a si mismo. Se usa token_get_all() para descartar comentarios,
     * docblocks y el propio cuerpo de esta prueba.
     */
    public function test_migrations_models_and_seeders_use_no_engine_specific_sql(): void
    {
        $forbidden = ['DATE_FORMAT', 'strftime(', 'DATE_TRUNC', 'DB::raw(', 'JSON_EXTRACT', 'ON CONFLICT', 'IFNULL('];
        $roots = [
            database_path('migrations'),
            database_path('seeders'),
            database_path('factories'),
            app_path('Models'),
        ];

        $offenders = [];

        foreach ($roots as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($files as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $code = $this->stripComments(file_get_contents($file->getPathname()));

                foreach ($forbidden as $needle) {
                    if (str_contains($code, $needle)) {
                        $offenders[] = $file->getFilename().' -> '.$needle;
                    }
                }
            }
        }

        $this->assertSame([], $offenders, 'SQL no portable detectado: '.implode(', ', $offenders));
    }

    private function stripComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= $token[1];

                continue;
            }
            $code .= $token;
        }

        return $code;
    }
}
