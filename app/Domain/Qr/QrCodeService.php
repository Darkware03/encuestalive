<?php

namespace App\Domain\Qr;

use App\Models\Survey;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;
use RuntimeException;

/**
 * Generacion del QR de una encuesta publicada (CA-02, API.md §3.1).
 *
 * PNG con el backend GD y SVG sin extensiones. `imagick` NO existe en este
 * build de PHP y no se puede instalar (ARCHITECTURE.md V3/V15); el build
 * alternativo que si lo trae carece de `pdo_sqlite`, asi que era inviable.
 *
 * ================= QUE SE CODIFICA, Y SOLO ESO =================
 * Exactamente `{APP_URL}/s/{publicId}`. Ni parametros de campana, ni id
 * interno, ni token, ni nada que convierta el QR impreso en un portador de
 * informacion adicional. Lo que se imprime en un cartel y se pega en una
 * pared debe poder leerlo cualquiera sin revelar nada.
 * ===============================================================
 *
 * Nivel de correccion de errores QUARTILE (~25 %), no el minimo. Un QR de
 * EncuestaLive vive impreso en un cartel de una sala: va a estar arrugado,
 * con reflejos, fotografiado en diagonal y desde lejos. La redundancia extra
 * cuesta unos pocos modulos y compra tolerancia real a todo eso.
 */
class QrCodeService
{
    public const MIN_SIZE = 128;
    public const MAX_SIZE = 1024;
    public const DEFAULT_SIZE = 512;

    /**
     * URL publica que el QR codifica. Es tambien la que debe abrirse al
     * escanearlo, y la que la prueba de CA-02 decodifica para comprobarlo.
     */
    public function publicUrl(Survey $survey): string
    {
        if ($survey->public_id === null) {
            throw new RuntimeException('La encuesta no tiene identificador publico: nunca se publico.');
        }

        return rtrim((string) config('app.url'), '/') . '/s/' . $survey->public_id;
    }

    public function png(Survey $survey, int $size = self::DEFAULT_SIZE): string
    {
        return $this->build($survey, $size, new PngWriter());
    }

    public function svg(Survey $survey, int $size = self::DEFAULT_SIZE): string
    {
        return $this->build($survey, $size, new SvgWriter());
    }

    public function dataUri(Survey $survey, int $size = self::DEFAULT_SIZE): string
    {
        return 'data:image/png;base64,' . base64_encode($this->png($survey, $size));
    }

    private function build(Survey $survey, int $size, PngWriter|SvgWriter $writer): string
    {
        $size = $this->clampSize($size);

        return (new Builder(
            writer: $writer,
            data: $this->publicUrl($survey),
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Quartile,
            size: $size,
            // Zona de silencio. Sin ella, muchos lectores fallan cuando el QR
            // queda pegado a un borde o a un texto en el cartel impreso.
            margin: 16,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
        ))->build()->getString();
    }

    public function clampSize(mixed $size): int
    {
        $size = is_numeric($size) ? (int) $size : self::DEFAULT_SIZE;

        return max(self::MIN_SIZE, min(self::MAX_SIZE, $size));
    }

    public function downloadName(Survey $survey, string $format): string
    {
        return sprintf('encuestalive-%s.%s', $survey->public_id, $format);
    }
}
