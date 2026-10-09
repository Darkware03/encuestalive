<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Qr\QrCodeService;
use App\Http\Controllers\Controller;
use App\Models\Survey;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Descarga del QR en PNG y SVG (API.md §3.1, CA-02).
 *
 * Los dos formatos no son un capricho: el PNG se pega en una presentacion o se
 * envia por mensajeria, y el SVG es el que de verdad sirve para IMPRIMIR un
 * cartel A3 sin que los modulos salgan borrosos. Un QR impreso borroso no se
 * escanea, y entonces la encuesta no existe.
 */
class QrController extends Controller
{
    public function __construct(
        private readonly QrCodeService $qr,
    ) {}

    public function __invoke(Request $request, Survey $survey, string $format): Response
    {
        $this->authorize('viewQr', $survey);

        abort_unless(in_array($format, ['png', 'svg'], true), 404);

        // 409 y no 404: la encuesta existe y es suya, pero todavia no tiene
        // identificador publico porque nunca se publico (API.md §3.1).
        abort_if($survey->public_id === null, 409, 'Publica la encuesta para generar su QR.');

        $size = $this->qr->clampSize($request->query('size', QrCodeService::DEFAULT_SIZE));

        [$body, $mime] = $format === 'png'
            ? [$this->qr->png($survey, $size), 'image/png']
            : [$this->qr->svg($survey, $size), 'image/svg+xml'];

        return response($body, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => sprintf(
                'attachment; filename="%s"',
                $this->qr->downloadName($survey, $format),
            ),
            // El QR de una encuesta no cambia nunca (el public_id no se
            // regenera), pero es un dato de una encuesta privada: `private`
            // evita que un proxy compartido lo sirva a otra persona.
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
