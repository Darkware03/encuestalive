<?php

namespace Tests\Feature\Qr;

use App\Domain\Qr\QrCodeService;
use App\Models\Survey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsSurveys;
use Tests\TestCase;

/**
 * QR y enlace publico — CA-02.
 *
 * LIMITE DECLARADO (D-09): aqui se comprueba que el QR se genera, que es un
 * PNG/SVG valido y que la URL EMBEBIDA es la correcta. NADIE lo ha escaneado
 * con un telefono real. CA-02 queda, como exige D-09, parcialmente
 * verificado; declararlo aprobado estaria prohibido.
 */
class QrCodeTest extends TestCase
{
    use RefreshDatabase, BuildsSurveys;

    // ------------------------------------------ la URL que se codifica

    /**
     * Lo que de verdad importa de un QR: que apunte a donde debe.
     *
     * Se comprueba la URL embebida, que es el dato que el lector extraera.
     */
    public function test_the_qr_encodes_exactly_the_public_url_of_that_survey(): void
    {
        config(['app.url' => 'http://127.0.0.1:8000']);

        $survey = Survey::factory()->published()->create();
        $qr = app(QrCodeService::class);

        $expected = 'http://127.0.0.1:8000/s/' . $survey->public_id;

        $this->assertSame($expected, $qr->publicUrl($survey));

        // Y esa URL resuelve de verdad a la encuesta publicada.
        $this->get('/s/' . $survey->public_id)->assertOk();
    }

    /**
     * El SVG es un QR valido y NO filtra nada por el marcado.
     *
     * CORRECCION DE UNA SUPOSICION MIA: la primera version de esta prueba
     * afirmaba que el SVG "lleva la URL en sus metadatos". NO ES CIERTO: el
     * SvgWriter de endroid emite solo geometria (<rect> y <path>), sin texto.
     * La prueba fallo y la suposicion se corrige aqui en lugar de relajar la
     * asercion hasta que pasara.
     *
     * Que no lleve la URL en claro es, de hecho, lo correcto: el contenido
     * debe estar en los modulos, no duplicado en un atributo.
     */
    public function test_the_svg_is_a_valid_qr_without_leaking_anything_in_the_markup(): void
    {
        config(['app.url' => 'http://127.0.0.1:8000']);

        $survey = Survey::factory()->published()->create();
        $svg = app(QrCodeService::class)->svg($survey, 320);

        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('<path', $svg);

        // Ni parametros de campana, ni id interno (API.md §3.1).
        $this->assertStringNotContainsString('utm_', $svg);
        $this->assertStringNotContainsString('"id":' . $survey->id, $svg);
    }

    /**
     * Que el QR codifica EXACTAMENTE la URL publica, verificado por
     * equivalencia de modulos.
     *
     * LIMITACION DECLARADA, porque importa: esto NO es una decodificacion
     * independiente. No hay ninguna libreria de LECTURA de QR en este
     * entorno, y escribir un decodificador completo a mano (correccion
     * Reed-Solomon incluida) seria mas codigo sin probar que el que se
     * intenta probar.
     *
     * Lo que si demuestra, y no es poco:
     *   1. el PNG del survey es BYTE A BYTE el de un QR de la URL esperada;
     *   2. una URL distinta produce un PNG distinto, asi que la prueba
     *      PUEDE fallar y no es una tautologia;
     *   3. esa misma URL resuelve de verdad a la encuesta.
     *
     * El escaneo con un telefono real sigue SIN HACERSE (D-09): CA-02 queda
     * parcialmente verificado.
     */
    public function test_the_generated_png_matches_a_qr_of_exactly_the_public_url(): void
    {
        config(['app.url' => 'http://127.0.0.1:8000']);

        $survey = Survey::factory()->published()->create();
        $expectedUrl = 'http://127.0.0.1:8000/s/' . $survey->public_id;

        $actual = app(QrCodeService::class)->png($survey, 512);
        $reference = $this->qrPngOf($expectedUrl, 512);

        $this->assertSame(
            md5($reference),
            md5($actual),
            'El QR debe codificar exactamente la URL publica de esa encuesta.',
        );

        // Control negativo: si el contenido cambia, el PNG cambia. Sin esto,
        // la comparacion anterior podria pasar por construccion.
        $this->assertNotSame(
            md5($this->qrPngOf($expectedUrl . '?utm=campana', 512)),
            md5($actual),
            'Un contenido distinto debe producir un QR distinto.',
        );

        // Y la URL codificada lleva de verdad a la encuesta.
        $this->get('/s/' . $survey->public_id)->assertOk();
    }

    /** QR de referencia construido con el mismo backend GD. */
    private function qrPngOf(string $data, int $size): string
    {
        return (new \Endroid\QrCode\Builder\Builder(
            writer: new \Endroid\QrCode\Writer\PngWriter(),
            data: $data,
            encoding: new \Endroid\QrCode\Encoding\Encoding('UTF-8'),
            errorCorrectionLevel: \Endroid\QrCode\ErrorCorrectionLevel::Quartile,
            size: $size,
            margin: 16,
            roundBlockSizeMode: \Endroid\QrCode\RoundBlockSizeMode::Margin,
        ))->build()->getString();
    }

    // --------------------------------------------------- endpoints HTTP

    public function test_the_owner_can_download_the_qr_in_png_and_svg(): void
    {
        $owner = $this->owner();
        $survey = Survey::factory()->published()->create(['created_by_user_id' => $owner->id]);

        $png = $this->actingAs($owner)->get(route('admin.surveys.qr', [
            'survey' => $survey, 'format' => 'png',
        ]))->assertOk();

        $this->assertSame('image/png', $png->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment;', $png->headers->get('Content-Disposition'));
        $this->assertStringStartsWith("\x89PNG", $png->getContent());

        $svg = $this->actingAs($owner)->get(route('admin.surveys.qr', [
            'survey' => $survey, 'format' => 'svg',
        ]))->assertOk();

        $this->assertSame('image/svg+xml', $svg->headers->get('Content-Type'));
    }

    public function test_an_unpublished_survey_has_no_qr(): void
    {
        $owner = $this->owner();
        $draft = Survey::factory()->draft()->create(['created_by_user_id' => $owner->id]);

        // 409 y no 404: existe y es suya, pero nunca se publico (API.md §3.1).
        $this->actingAs($owner)
            ->get(route('admin.surveys.qr', ['survey' => $draft, 'format' => 'png']))
            ->assertStatus(409);
    }

    public function test_the_size_is_clamped_to_a_sane_range(): void
    {
        $qr = app(QrCodeService::class);

        $this->assertSame(QrCodeService::MIN_SIZE, $qr->clampSize(1));
        $this->assertSame(QrCodeService::MAX_SIZE, $qr->clampSize(99999));
        $this->assertSame(512, $qr->clampSize(512));
        $this->assertSame(QrCodeService::DEFAULT_SIZE, $qr->clampSize('no-es-un-numero'));
    }
}
