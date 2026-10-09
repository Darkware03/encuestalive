<?php

namespace App\Http\Middleware;

use App\Domain\Surveys\PublicIdGenerator;
use App\Domain\Surveys\SurveyService;
use App\Domain\Surveys\SurveyStateMachine;
use App\Models\Survey;
use App\Support\SecurityLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resuelve `/s/{publicId}` a una encuesta publicamente visible.
 *
 * ============ POR QUE TODO ES 404 Y NUNCA 403 ============
 * Un borrador, una programada, una archivada, un identificador inexistente y
 * uno mal formado devuelven EXACTAMENTE la misma respuesta: 404.
 *
 * Distinguirlos permitiria enumerar: probando identificadores, un atacante
 * sabria cuales existen pero estan en borrador, y con ello cuantas encuestas
 * hay y cuando se crean. Eso es informacion administrativa filtrada por una
 * ruta publica (brief §6, API.md §0.6).
 *
 * Las encuestas cerradas y pausadas SI resuelven, porque el participante debe
 * poder ver la pantalla "esta encuesta ya no admite respuestas" en lugar de un
 * 404 desconcertante despues de escanear un QR impreso que alguien le dio.
 * =========================================================
 */
class ResolvePublicSurvey
{
    public function __construct(
        private readonly SurveyStateMachine $states,
        private readonly SurveyService $surveys,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $publicId = (string) $request->route('publicId');

        // Forma antes que base de datos: un identificador mal formado no llega
        // a tocar el indice. Tambien evita que una cadena larguisima o con
        // caracteres raros viaje hasta la consulta.
        if (! PublicIdGenerator::isWellFormed($publicId)) {
            SecurityLog::invalidPublicId($request);

            abort(404);
        }

        $survey = Survey::query()->where('public_id', $publicId)->first();

        if ($survey === null) {
            SecurityLog::invalidPublicId($request);

            abort(404);
        }

        /*
         * Estado EFECTIVO antes de decidir (RN-11). Una encuesta programada
         * cuyo inicio ya llego debe abrirse, y una activa cuyo fin ya paso
         * debe estar cerrada, sin depender de que un proceso en segundo plano
         * haya corrido. No hay demonio de relojes: el estado se resuelve la
         * primera vez que alguien mira.
         */
        $survey = $this->surveys->activateIfDue($survey);
        $survey = $this->surveys->closeIfExpired($survey);

        if (! $this->states->isPubliclyVisible($survey)) {
            // Indistinguible de "no existe", a proposito.
            abort(404);
        }

        $request->attributes->set(AttachParticipantSession::SURVEY_ATTRIBUTE, $survey);

        return $next($request);
    }
}
