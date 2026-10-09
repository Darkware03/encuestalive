<?php

namespace App\Http\Middleware;

use App\Domain\Surveys\SurveyStateMachine;
use App\Models\Survey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * LA PUERTA UNICA que decide si se aceptan respuestas (CA-13).
 *
 * ================== POR QUE ESTO ES UN MIDDLEWARE ==================
 * Centralizarlo es el punto entero. Si cada controlador y cada componente
 * Livewire tuviera que acordarse de comprobar el estado, CA-13 dependeria de
 * que nadie se olvidase nunca. Aqui se comprueba una vez, en un sitio, y
 * ninguna ruta de escritura de participante puede saltarselo.
 *
 * ESTO NO ES COSMETICA DE INTERFAZ. Ocultar el boton de enviar no cierra nada:
 * una peticion con `curl` a una encuesta cerrada llega igual. El rechazo
 * ocurre AQUI, en el servidor, con 410, y antes de que el controlador vea la
 * peticion.
 * ===================================================================
 *
 * Orden de comprobacion (ARCHITECTURE.md §5.3): existe · activa · dentro de
 * la ventana temporal.
 */
class EnsureSurveyAcceptsResponses
{
    public function __construct(
        private readonly SurveyStateMachine $states,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $survey = $request->attributes->get(AttachParticipantSession::SURVEY_ATTRIBUTE);

        if (! $survey instanceof Survey) {
            abort(404);
        }

        // Cerrada, pausada, archivada... cualquier cosa que no sea `active`.
        if (! $this->states->acceptsResponses($survey)) {
            $this->reject($request, $survey);
        }

        // Ventana temporal. `starts_at` en el futuro significa que la encuesta
        // esta publicada pero aun no abierta.
        if ($survey->starts_at !== null && $survey->starts_at->isFuture()) {
            $this->reject($request, $survey);
        }

        if ($survey->ends_at !== null && $survey->ends_at->isPast()) {
            $this->reject($request, $survey);
        }

        return $next($request);
    }

    private function reject(Request $request, Survey $survey): never
    {
        $message = match ($survey->status) {
            Survey::STATUS_PAUSED => 'Esta encuesta esta pausada ahora mismo.',
            default => 'Esta encuesta ya no admite respuestas.',
        };

        /*
         * 410 Gone y no 403: el recurso existe y la persona tenia derecho a
         * responder, pero el momento paso. Ademas el cliente lo usa como senal
         * para DETENER los heartbeats (API.md §2.3), cosa que un 403 no
         * comunicaria.
         */
        abort(response()->json([
            'message' => $message,
            'status' => $survey->status,
            'correlation_id' => $request->attributes->get('el.correlation_id'),
        ], 410));
    }
}
