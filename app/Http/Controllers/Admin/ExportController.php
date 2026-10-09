<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Export\CsvExporter;
use App\Http\Controllers\Controller;
use App\Models\Survey;
use App\Support\SecurityLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exportacion CSV (API.md §3.2, CA-11, CA-14).
 *
 * Autorizacion real por `ExportPolicy`: un administrador NO puede exportar una
 * encuesta ajena (403). La matriz de API.md §6 lo fija explicitamente, y es
 * uno de los controles de CA-12.
 *
 * El contenido del fichero lo construye CsvExporter con una lista blanca de
 * seis columnas; aqui no se anade ni una.
 */
class ExportController extends Controller
{
    public function __construct(
        private readonly CsvExporter $exporter,
    ) {}

    public function __invoke(Request $request, Survey $survey): StreamedResponse
    {
        try {
            $this->authorize('export', $survey);
        } catch (AuthorizationException $e) {
            // El intento denegado se registra en la isla B: quien intento
            // exportar que, y desde donde. Es justo el tipo de evento para el
            // que `security_events` existe.
            SecurityLog::exportDenied($request, $request->user()?->id, $survey->id);

            throw $e;
        }

        $includeOpenText = $request->boolean('include_open_text', true);
        $filename = $this->exporter->filename($survey);

        $rows = 0;

        $response = new StreamedResponse(function () use ($survey, $includeOpenText, &$rows): void {
            $handle = fopen('php://output', 'wb');

            foreach ($this->exporter->stream($survey, $includeOpenText) as $chunk) {
                fwrite($handle, $chunk);
                $rows++;

                /*
                 * Vaciado explicito por trozos. Sin esto, PHP acumula la
                 * salida en memoria y el streaming no sirve de nada: una
                 * encuesta de 300 participantes y 10 preguntas son 3000 filas
                 * que acabarian enteras en RAM antes de enviar el primer byte.
                 */
                if ($rows % 100 === 0) {
                    flush();
                }
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set(
            'Content-Disposition',
            sprintf('attachment; filename="%s"', $filename),
        );
        // Un CSV de resultados no debe quedar en ninguna cache intermedia.
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        SecurityLog::exportPerformed(
            $request,
            (int) $request->user()?->id,
            (int) $survey->id,
            $survey->responses()->count(),
        );

        return $response;
    }
}
