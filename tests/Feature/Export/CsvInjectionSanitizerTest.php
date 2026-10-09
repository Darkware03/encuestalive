<?php

namespace Tests\Feature\Export;

use App\Domain\Export\CsvInjectionSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * F-03 (ALTO) y F-04 (BAJO), Fase 7 — EVASION DEL SANEADOR CSV.
 *
 * ======================================================================
 *  EL FALLO
 * ======================================================================
 * El saneador comparaba el PRIMER caracter contra una lista de prefijos
 * peligrosos. La lista incluia TAB y CR con un razonamiento correcto
 * ("algunas hojas de calculo los recortan al abrir y dejan al descubierto el
 * caracter siguiente"), pero ese mismo razonamiento dejaba fuera a LF, al
 * espacio y al NBSP. El auditor probo los tres y los tres escapaban.
 *
 * Las cargas de este fichero son LITERALMENTE las del auditor
 * (`security/encuestalive/pruebas/p4_csv_bypass.php`), para que la suite
 * cubra exactamente lo que se demostro roto y no una version domesticada.
 *
 * Las pruebas de evasion de este fichero FALLAN con el codigo anterior.
 */
class CsvInjectionSanitizerTest extends TestCase
{
    private CsvInjectionSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sanitizer = new CsvInjectionSanitizer();
    }

    /**
     * Las cargas que el auditor demostro que ESCAPABAN.
     *
     * @return array<string, array{0: string}>
     */
    public static function auditorBypassPayloads(): array
    {
        return [
            'LF + formula' => ["\n=1+1"],
            'ESPACIO + formula' => [' =1+1'],
            'LF+LF + DDE' => ["\n\n=cmd|'/c calc'!A1"],
            'espacio + arroba' => [' @SUM(1)'],
            'TAB tras LF' => ["\n\t=1+1"],
            'NBSP (U+00A0) + formula' => ["\u{00A0}=1+1"],
        ];
    }

    /**
     * Espacios en blanco Unicode que NO estaban en ninguna lista y que una
     * hoja de calculo puede recortar igual que el espacio normal.
     *
     * Se incluyen porque mantener una lista negra de "espacios peligrosos" es
     * perder la carrera: la correccion recorta el espacio en su sentido
     * Unicode completo, y esto lo verifica.
     *
     * @return array<string, array{0: string}>
     */
    public static function unicodeWhitespacePayloads(): array
    {
        return [
            'EN QUAD U+2000' => ["\u{2000}=1+1"],
            'EM SPACE U+2003' => ["\u{2003}=1+1"],
            'THIN SPACE U+2009' => ["\u{2009}=1+1"],
            'NARROW NBSP U+202F' => ["\u{202F}=1+1"],
            'IDEOGRAPHIC SPACE U+3000' => ["\u{3000}=1+1"],
            'BOM U+FEFF' => ["\u{FEFF}=1+1"],
            'ZERO WIDTH SPACE U+200B' => ["\u{200B}=1+1"],
            'VERTICAL TAB' => ["\x0B=1+1"],
            'FORM FEED' => ["\x0C=1+1"],
            'mezcla espacio+LF+TAB' => [" \n\t  +1+1"],
            'CRLF + menos' => ["\r\n-1+1"],
            'NBSP + arroba' => ["\u{00A0}\u{00A0}@SUM(1)"],
        ];
    }

    /**
     * Los prefijos clasicos, que ya funcionaban. Se conservan para que la
     * correccion no retroceda mientras arregla lo otro.
     *
     * @return array<string, array{0: string}>
     */
    public static function classicPayloads(): array
    {
        return [
            'igual' => ['=1+1'],
            'mas' => ['+1+1'],
            'menos' => ['-1+1'],
            'arroba' => ['@SUM(A1)'],
            'TAB' => ["\t=1+1"],
            'CR' => ["\r=1+1"],
            'NUL' => ["\x00=1+1"],
            'US (0x1F)' => ["\x1F=1+1"],
            'DDE' => ["=cmd|'/c calc'!A1"],
            'hipervinculo' => ['=HYPERLINK("http://malo.test","clic")'],
        ];
    }

    // =================================================================
    //  F-03 — las evasiones
    // =================================================================

    /**
     * ===== LAS CARGAS DEL AUDITOR =====
     *
     * FALLA antes: estas seis salian SIN apostrofo, con la formula desnuda.
     */
    #[DataProvider('auditorBypassPayloads')]
    public function test_the_auditor_bypass_payloads_are_neutralised(string $payload): void
    {
        $cell = $this->sanitizer->sanitize($payload);

        $this->assertStringStartsWith("'", $cell, sprintf(
            'Carga NO neutralizada: %s. Un participante que escriba esto en un campo de '
            .'texto libre obtiene una celda viva en el CSV que abre el administrador (F-03).',
            json_encode($payload),
        ));
    }

    /** El espacio en blanco Unicode tampoco puede usarse como rampa. */
    #[DataProvider('unicodeWhitespacePayloads')]
    public function test_unicode_whitespace_cannot_smuggle_a_formula(string $payload): void
    {
        $this->assertStringStartsWith(
            "'",
            $this->sanitizer->sanitize($payload),
            'Carga NO neutralizada: '.json_encode($payload),
        );
    }

    /** Sin regresiones en lo que ya protegia. */
    #[DataProvider('classicPayloads')]
    public function test_classic_dangerous_prefixes_remain_neutralised(string $payload): void
    {
        $this->assertStringStartsWith("'", $this->sanitizer->sanitize($payload));
    }

    /**
     * El dato del participante se conserva INTACTO tras el apostrofo.
     *
     * La neutralizacion no puede convertirse en una mutilacion: recortar el
     * espacio inicial del dato exportado seria perder informacion real. El
     * recorte es solo para DECIDIR.
     */
    public function test_the_original_text_is_preserved_untouched_after_the_apostrophe(): void
    {
        $this->assertSame("'\n=1+1", $this->sanitizer->sanitize("\n=1+1"));
        $this->assertSame("' =1+1", $this->sanitizer->sanitize(' =1+1'));
        $this->assertSame("'\u{00A0}=1+1", $this->sanitizer->sanitize("\u{00A0}=1+1"));
    }

    /**
     * Y la fila completa RFC 4180 tampoco deja la formula al aire.
     *
     * Es la comprobacion de extremo a extremo del formato: el auditor pego
     * precisamente las filas crudas como evidencia.
     */
    public function test_the_full_rfc4180_row_contains_no_live_formula(): void
    {
        foreach (["\n=1+1", ' =1+1', "\u{00A0}=1+1"] as $payload) {
            $row = $this->sanitizer->row(['q1', $payload]);

            $this->assertStringNotContainsString('"'."\n".'=1+1"', $row);
            $this->assertStringContainsString("'", $row, 'La fila no neutralizo: '.json_encode($row));
        }
    }

    // =================================================================
    //  No neutralizar de mas
    // =================================================================

    /**
     * Un CSV lleno de apostrofos espurios es ilegible. Lo inofensivo no se
     * toca, incluido el texto que SOLO lleva espacio inicial.
     */
    public function test_harmless_values_are_not_altered(): void
    {
        $this->assertSame('Excelente', $this->sanitizer->sanitize('Excelente'));
        $this->assertSame('10', $this->sanitizer->sanitize('10'));
        $this->assertSame(' me gusto mucho', $this->sanitizer->sanitize(' me gusto mucho'));
        $this->assertSame("\nSegunda linea", $this->sanitizer->sanitize("\nSegunda linea"));
        $this->assertSame('   ', $this->sanitizer->sanitize('   '));
        $this->assertSame("\u{00A0}hola", $this->sanitizer->sanitize("\u{00A0}hola"));
    }

    /** Una celda de solo espacios no es una formula y no se neutraliza. */
    public function test_whitespace_only_cells_are_not_flagged(): void
    {
        $this->assertFalse($this->sanitizer->isDangerous("\n\t  "));
        $this->assertFalse($this->sanitizer->isDangerous("\u{00A0}"));
        $this->assertFalse($this->sanitizer->isDangerous(''));
    }

    // =================================================================
    //  F-04 — UTF-8 invalido
    // =================================================================

    /**
     * UTF-8 invalido ya NO vacia la celda entera.
     *
     * FALLA antes: `preg_replace` con /u devolvia null, el `?? ''` lo absorbia
     * y la respuesta legitima desaparecia del CSV sin aviso. Integridad de
     * datos, no seguridad, pero una perdida silenciosa igualmente.
     */
    public function test_invalid_utf8_no_longer_empties_the_whole_cell(): void
    {
        $cell = $this->sanitizer->sanitize("\xC3\x28=1+1");

        $this->assertNotSame('', $cell, 'La celda se vacio entera: se perdio el dato (F-04).');
        $this->assertStringContainsString('1+1', $cell, 'Debe conservarse el texto recuperable.');
    }

    /**
     * Y, sobre todo, el UTF-8 invalido ya no sirve para SALTARSE la
     * neutralizacion.
     *
     * Antes, un byte invalido delante hacia que la funcion devolviera ''
     * (sin comprobar nada). Ahora se descartan los bytes invalidos y el texto
     * resultante vuelve a pasar por la comprobacion de prefijo.
     */
    public function test_invalid_utf8_cannot_be_used_to_skip_the_prefix_check(): void
    {
        foreach (["\xFF=1+1", "\xC3=cmd|'/c calc'!A1", "\xED\xA0\x80+1+1"] as $payload) {
            $this->assertStringStartsWith("'", $this->sanitizer->sanitize($payload), sprintf(
                'Un byte UTF-8 invalido permitio colar la formula: %s',
                bin2hex($payload),
            ));
        }
    }

    /** El texto valido no pasa por el camino de reparacion. */
    public function test_valid_utf8_with_accents_is_untouched(): void
    {
        $this->assertSame('Opinión: ñandú áéíóú', $this->sanitizer->sanitize('Opinión: ñandú áéíóú'));
    }

    // =================================================================
    //  Coherencia con la configuracion declarada
    // =================================================================

    /**
     * `config/encuestalive.php` declara `export.formula_prefixes`. Si la
     * configuracion y la constante divergen, la documentacion miente sobre lo
     * que el codigo hace.
     *
     * Se comprueba la propiedad que de verdad importa: todo prefijo
     * declarado se neutraliza cuando es el primer caracter SIGNIFICATIVO,
     * este desnudo o detras de cualquier espacio en blanco.
     *
     * Esta prueba encontro un defecto real al escribirse: la configuracion
     * seguia declarando "\t" y "\r" como prefijos de formula cuando la
     * correccion de F-03 los habia convertido en espacio a recortar. La
     * configuracion describia un control que ya no era el implementado.
     */
    public function test_every_configured_formula_prefix_is_actually_neutralised(): void
    {
        $configured = (array) config('encuestalive.export.formula_prefixes', []);

        $this->assertNotEmpty($configured, 'La configuracion debe declarar los prefijos.');

        // Desnudo y detras de cada clase de espacio en blanco relevante.
        $leadings = ['', ' ', "\t", "\r", "\n", "\u{00A0}", "\u{3000}", " \n\t "];

        foreach ($configured as $prefix) {
            $this->assertContains($prefix, ['=', '+', '-', '@'], sprintf(
                'config declara %s como prefijo de formula. Si es espacio en blanco no '
                .'pertenece a esta lista: el saneador lo recorta ANTES de decidir, de modo '
                .'que declararlo aqui describe un control que ya no es el implementado (F-03).',
                json_encode($prefix),
            ));

            foreach ($leadings as $leading) {
                $this->assertStringStartsWith(
                    "'",
                    $this->sanitizer->sanitize($leading.$prefix.'1+1'),
                    sprintf(
                        'El prefijo %s detras de %s esta declarado en config/encuestalive.php '
                        .'pero el saneador no lo neutraliza.',
                        json_encode($prefix),
                        json_encode($leading),
                    ),
                );
            }
        }
    }
}
