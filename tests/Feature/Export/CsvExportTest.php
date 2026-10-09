<?php

namespace Tests\Feature\Export;

use App\Domain\Export\CsvInjectionSanitizer;
use App\Models\SecurityEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsSurveys;
use Tests\TestCase;

/**
 * Exportacion CSV — CA-11 (sin IP ni identificadores tecnicos) y CA-14.
 */
class CsvExportTest extends TestCase
{
    use RefreshDatabase, BuildsSurveys;

    private function exportCsv($survey, $owner): string
    {
        $response = $this->actingAs($owner)
            ->get(route('admin.surveys.export', $survey))
            ->assertOk();

        return $response->streamedContent();
    }

    // ------------------------------------------ CA-11 fuga de datos

    /**
     * CA-11: la exportacion NO incluye IP ni identificadores tecnicos.
     *
     * Se siembra a proposito un `security_event` con una IP real del rango de
     * documentacion, del mismo minuto que la respuesta, para que la prueba se
     * enfrente al caso incomodo y no a uno comodo.
     */
    public function test_the_csv_never_contains_ip_or_technical_identifiers(): void
    {
        $owner = $this->owner();
        $built = $this->publishedSurveyWithAllTypes($owner);

        $this->openSurvey($built['survey']);
        $this->submitAs($built['survey'], $this->validAnswers($built))->assertOk();

        SecurityEvent::create([
            'occurred_at' => now(),
            'event_type' => SecurityEvent::TYPE_RATE_LIMITED,
            'ip_address' => '192.0.2.44',
            'route' => 's/{publicId}',
            'http_status' => 429,
            'survey_id' => $built['survey']->id,
        ]);

        $session = \App\Models\ParticipantSession::query()->first();
        $csv = $this->exportCsv($built['survey'], $owner);

        // 1. Ninguna IP, ni la sembrada ni ninguna otra.
        $this->assertStringNotContainsString('192.0.2.44', $csv);
        $this->assertSame(
            0,
            preg_match('/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', $csv),
            'El CSV no debe contener ninguna direccion IP.',
        );

        // 2. Ni el hash del token, ni el token, ni el id de sesion.
        $this->assertStringNotContainsString($session->token_hash, $csv);

        /*
         * 3. Las columnas son EXACTAMENTE las declaradas. Lista blanca, no
         *    lista negra.
         *
         * La primera version de esta prueba buscaba la subcadena 'ip' en la
         * cabecera y fallaba contra la columna legitima "tipo", que la
         * contiene. Es EXACTAMENTE el falso positivo que D-13 registro en
         * DECISIONS.md: una expresion /ip|agent|host/ marco `description`,
         * `snapshot_participated` y `participant_session_id` por coincidencia
         * lexica. Verificar mal es peor que no verificar, porque produce
         * acusaciones falsas.
         *
         * Afirmar el conjunto exacto de columnas es mas fuerte ademas de mas
         * correcto: una columna nueva, se llame como se llame, rompe la
         * prueba y obliga a justificarla.
         */
        $header = explode("\r\n", $csv)[0] ?? '';
        $columns = array_map(
            fn (string $c) => trim($c, '"'),
            explode(',', ltrim($header, "\xEF\xBB\xBF")),
        );

        $this->assertSame(
            \App\Domain\Export\CsvExporter::DETAIL_HEADERS,
            $columns,
            'El CSV debe exportar exactamente las columnas declaradas.',
        );

        // Y ningun nombre de columna puede ser un identificador tecnico,
        // comparando por PALABRA completa y no por subcadena.
        foreach ($columns as $column) {
            $this->assertSame(
                0,
                preg_match('/(^|_)(ip|token|session|sesion|agent|correlation|hash)(_|$)/', $column),
                "La columna '{$column}' parece un identificador tecnico.",
            );
        }
    }

    public function test_the_response_ordinal_is_local_to_the_file_and_not_an_internal_id(): void
    {
        $owner = $this->owner();
        $built = $this->publishedSurveyWithAllTypes($owner);

        // Tres participantes distintos.
        for ($i = 0; $i < 3; $i++) {
            $this->newParticipant();
            $this->openSurvey($built['survey']);
            $this->submitAs($built['survey'], $this->validAnswers($built))->assertOk();
        }

        $csv = $this->exportCsv($built['survey'], $owner);

        /*
         * Se analiza SOLO la seccion de detalle, hasta la linea en blanco que
         * la separa del resumen.
         *
         * La primera version recorria el fichero entero con /^"(\d+)"/ y daba
         * un FALSO POSITIVO: la primera columna del resumen es
         * `pregunta_orden`, tambien numerica, asi que la prueba encontraba
         * "1","2","3" aunque hubiera un unico participante. Pasaba en verde
         * sin comprobar nada.
         */
        $detail = explode("\r\n\r\n", $csv)[0];
        $lines = array_values(array_filter(explode("\r\n", $detail)));
        array_shift($lines); // cabecera

        $ordinals = [];
        foreach ($lines as $line) {
            if (preg_match('/^"(\d+)"/', $line, $m)) {
                $ordinals[(int) $m[1]] = true;
            }
        }

        // Tres participantes distintos -> ordinales 1, 2 y 3 reasignados
        // localmente, nunca los ids internos de `responses`.
        $this->assertSame([1, 2, 3], array_keys($ordinals));
        $this->assertSame(3, \App\Models\Response::query()->count());
    }

    // -------------------------------- CA-11 / brief §6.2 inyeccion CSV

    /**
     * Neutralizacion de formulas. El texto lo escribe un participante y lo
     * abre el administrador en Excel: es ahi donde se ejecutaria.
     */
    public function test_a_formula_in_free_text_is_neutralised_in_the_csv(): void
    {
        $owner = $this->owner();
        $built = $this->publishedSurveyWithAllTypes($owner);

        $payload = '=SUM(A1:A9)*cmd|\'/c calc\'!A1';

        $this->openSurvey($built['survey']);
        $this->submitAs($built['survey'], $this->validAnswers($built, $payload))->assertOk();

        // Se guarda EN CRUDO: el dato original del participante no se toca.
        $this->assertSame(
            $payload,
            \App\Models\ResponseAnswer::query()
                ->where('question_id', $built['text']->id)
                ->value('text_answer'),
        );

        $csv = $this->exportCsv($built['survey'], $owner);

        // En el fichero sale con apostrofo delante: ya no es una formula.
        $this->assertStringContainsString("'=SUM(A1:A9)", $csv);
        $this->assertStringNotContainsString('"=SUM(A1:A9)', $csv);
    }

    #[DataProvider('dangerousPayloads')]
    public function test_every_dangerous_prefix_is_neutralised(string $payload): void
    {
        $sanitizer = new CsvInjectionSanitizer();

        $cell = $sanitizer->sanitize($payload);

        $this->assertStringStartsWith("'", $cell, "No se neutralizo: {$payload}");
    }

    public static function dangerousPayloads(): array
    {
        return [
            'igual' => ['=1+1'],
            'mas' => ['+1+1'],
            'menos' => ['-1+1'],
            'arroba' => ['@SUM(A1)'],
            'tabulador' => ["\t=1+1"],
            'retorno de carro' => ["\r=1+1"],
            'DDE' => ['=cmd|\' /C calc\'!A0'],
            'hipervinculo' => ['=HYPERLINK("http://malo.test","clic")'],
        ];
    }

    /**
     * Un `\0` antes del `=` dejaria el prefijo fuera de la comprobacion si los
     * caracteres de control no se limpiaran ANTES de mirarlo.
     */
    public function test_control_characters_cannot_be_used_to_smuggle_a_formula(): void
    {
        $sanitizer = new CsvInjectionSanitizer();

        $this->assertStringStartsWith("'", $sanitizer->sanitize("\0=1+1"));
        $this->assertStringStartsWith("'", $sanitizer->sanitize("\x1F=1+1"));
    }

    public function test_quotes_are_doubled_and_cells_cannot_break_the_structure(): void
    {
        $sanitizer = new CsvInjectionSanitizer();

        $row = $sanitizer->row(['dice "hola"', "con\nsalto", 'normal']);

        $this->assertStringContainsString('"dice ""hola"""', $row);
        $this->assertStringEndsWith("\r\n", $row);
    }

    public function test_a_harmless_value_is_not_altered(): void
    {
        $sanitizer = new CsvInjectionSanitizer();

        // No se neutraliza lo que no hace falta: un CSV lleno de apostrofos
        // espurios seria ilegible.
        $this->assertSame('Excelente', $sanitizer->sanitize('Excelente'));
        $this->assertSame('10', $sanitizer->sanitize('10'));
    }

    // ------------------------------------------------- D-06 dos bases

    /**
     * D-06: el CSV exporta AMBAS bases de porcentaje en columnas separadas,
     * "para que nadie tenga que adivinar" cual se uso.
     */
    public function test_the_summary_exports_both_percentage_bases(): void
    {
        $owner = $this->owner();
        $built = $this->publishedSurveyWithAllTypes($owner);

        $this->openSurvey($built['survey']);
        $this->submitAs($built['survey'], $this->validAnswers($built))->assertOk();

        $csv = $this->exportCsv($built['survey'], $owner);

        $this->assertStringContainsString('pct_sobre_participantes', $csv);
        $this->assertStringContainsString('pct_sobre_selecciones', $csv);
        $this->assertStringContainsString('participantes_en_la_pregunta', $csv);
        $this->assertStringContainsString('selecciones_totales', $csv);
    }

    public function test_the_csv_has_a_utf8_bom_so_excel_does_not_mangle_accents(): void
    {
        $owner = $this->owner();
        $built = $this->publishedSurveyWithAllTypes($owner);

        $csv = $this->exportCsv($built['survey'], $owner);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
    }

    // -------------------------------------------------- autorizacion

    public function test_exporting_is_recorded_as_a_security_event(): void
    {
        $owner = $this->owner();
        $built = $this->publishedSurveyWithAllTypes($owner);

        $this->exportCsv($built['survey'], $owner);

        $this->assertTrue(
            SecurityEvent::query()->where('event_type', 'export_performed')->exists(),
        );
    }

    public function test_a_draft_cannot_be_exported(): void
    {
        $owner = $this->owner();
        $draft = \App\Models\Survey::factory()->draft()->create([
            'created_by_user_id' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->get(route('admin.surveys.export', $draft))
            ->assertForbidden();
    }
}
