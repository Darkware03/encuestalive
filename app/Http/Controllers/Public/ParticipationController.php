<?php

namespace App\Http\Controllers\Public;

use App\Domain\Participation\ParticipationService;
use App\Domain\Presence\PresenceService;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AttachParticipantSession;
use App\Models\ParticipantSession;
use App\Models\Survey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Superficie publica del participante (API.md §2 y §3).
 *
 * Ninguna de estas rutas pide cuenta, nombre ni correo. La unica continuidad
 * es la cookie opaca de ADR-0005, que NO es autenticacion: solo permite
 * reconocer "esta misma pestana" para presencia y anti-duplicado.
 */
class ParticipationController extends Controller
{
    public function __construct(
        private readonly ParticipationService $participation,
        private readonly PresenceService $presence,
    ) {}

    /**
     * `GET /s/{publicId}` — pantalla de participacion.
     *
     * Decide QUE pantalla segun el estado real, no segun lo que pida el
     * cliente: formulario, "ya respondiste", "cerrada" o "pausada".
     */
    public function show(Request $request): View
    {
        $survey = $this->survey($request);
        $session = $this->participantSession($request);

        $survey->load(['questions.options']);

        // Si esta sesion ya envio, NO se muestra el formulario. Mostrarlo
        // invitaria a rellenarlo otra vez para despues rechazar el envio, que
        // es la peor experiencia posible (RN-04: no hay edicion de respuesta).
        $alreadyAnswered = $session->submission_state === ParticipantSession::STATE_COMPLETED;

        return view('public.survey', [
            'survey' => $survey,
            'session' => $session,
            'alreadyAnswered' => $alreadyAnswered,
            'acceptsResponses' => $survey->status === Survey::STATUS_ACTIVE
                && ! ($survey->starts_at?->isFuture() ?? false)
                && ! ($survey->ends_at?->isPast() ?? false),
            'heartbeatSeconds' => $this->presence->heartbeatIntervalSeconds(),
        ]);
    }

    /**
     * `POST /s/{publicId}/session` — emite o reanuda la sesion anonima.
     *
     * El token NUNCA aparece en el cuerpo: viaja solo como cookie firmada
     * HttpOnly que escribe AttachParticipantSession (ADR-0005).
     */
    public function session(Request $request): JsonResponse
    {
        $session = $this->participantSession($request);
        $issued = (bool) $request->attributes->get('el.session_issued', false);

        return response()->json([
            'session' => [
                'issued' => $issued,
                'completed' => $session->submission_state === ParticipantSession::STATE_COMPLETED,
                'expires_in' => config('encuestalive.participant.token_ttl_minutes') * 60,
            ],
        ], $issued ? 201 : 200);
    }

    /**
     * `POST /s/{publicId}/heartbeat` — renueva la presencia.
     *
     * 204 sin cuerpo a proposito: es el endpoint mas frecuente del sistema
     * (300 sesiones cada 20 s) y no debe gastar ancho de banda de un movil en
     * una sala con la red saturada.
     */
    public function heartbeat(Request $request): Response
    {
        $this->participation->heartbeat($this->survey($request), $this->participantSession($request));

        return response()->noContent();
    }

    /**
     * `GET /s/{publicId}/state` — reconciliacion del cliente tras reconectar.
     *
     * Devuelve solo el estado publico y si ESTA sesion ya completo. Ningun
     * agregado, ningun recuento: eso es informacion del dashboard y viaja por
     * el canal privado del administrador (RN-18).
     */
    public function state(Request $request): JsonResponse
    {
        $survey = $this->survey($request);
        $session = $this->participantSession($request);

        return response()->json([
            'survey' => [
                'public_id' => $survey->public_id,
                'status' => $survey->status,
                'accepts_responses' => $survey->status === Survey::STATUS_ACTIVE
                    && ! ($survey->ends_at?->isPast() ?? false),
            ],
            'session' => [
                'completed' => $session->submission_state === ParticipantSession::STATE_COMPLETED,
                'completed_at' => $session->completed_at?->toIso8601ZuluString(),
            ],
        ]);
    }

    /**
     * `POST /s/{publicId}/responses` — el endpoint central.
     *
     * Llega aqui SOLO si EnsureSurveyAcceptsResponses lo permitio: la encuesta
     * cerrada o pausada se rechaza antes, en el middleware, con 410.
     */
    public function store(Request $request): JsonResponse
    {
        $survey = $this->survey($request);
        $session = $this->participantSession($request);

        $data = $request->validate([
            'submission_uuid' => ['required', 'uuid'],
            'answers' => ['required', 'array', 'min:1', 'max:100'],
        ]);

        $this->presence->markInProgress($session);

        $result = $this->participation->submit(
            survey: $survey,
            session: $session,
            submissionUuid: $data['submission_uuid'],
            answers: $request->input('answers', []),
        );

        // 200 tambien en el reenvio idempotente, con `duplicate: true`. Nunca
        // 409: el participante cuya red fallo despues de que el servidor
        // guardase hizo todo bien y no debe ver un error (CA-07).
        return response()->json($result->toArray(), 200);
    }

    /**
     * Envio desde el formulario HTML sin JavaScript (progresion, 16.d).
     *
     * El participante debe poder responder aunque el JS no cargue, que es lo
     * normal en un movil con mala cobertura. Reconstruye el mismo contrato que
     * el endpoint JSON y reutiliza exactamente el mismo servicio: no hay dos
     * caminos de validacion que puedan divergir.
     */
    public function storeForm(Request $request)
    {
        $survey = $this->survey($request);
        $session = $this->participantSession($request);

        $this->presence->markInProgress($session);

        try {
            $result = $this->participation->submit(
                survey: $survey,
                session: $session,
                // Sin JS no hay uuid de cliente; se genera uno estable por
                // sesion para que un reenvio del formulario (F5, boton atras)
                // siga chocando con la idempotencia y no cree una fila nueva.
                submissionUuid: (string) Str::uuid(),
                answers: $this->answersFromForm($request),
            );
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()
            ->route('public.survey.show', ['publicId' => $survey->public_id])
            ->with('receipt_id', $result->receiptId);
    }

    /**
     * Traduce el formulario plano (`q[12][]=45`) al contrato de `answers`.
     *
     * @return array<int, array>
     */
    private function answersFromForm(Request $request): array
    {
        $answers = [];

        foreach ((array) $request->input('q', []) as $questionId => $value) {
            $questionId = (int) $questionId;

            if (is_array($value)) {
                $answers[] = ['question_id' => $questionId, 'option_ids' => $value];

                continue;
            }

            // Un campo de texto llega como cadena; una opcion unica, como id.
            if (is_numeric($value)) {
                $answers[] = ['question_id' => $questionId, 'option_ids' => [(int) $value]];

                continue;
            }

            $answers[] = ['question_id' => $questionId, 'text' => (string) $value];
        }

        return $answers;
    }

    private function survey(Request $request): Survey
    {
        $survey = $request->attributes->get(AttachParticipantSession::SURVEY_ATTRIBUTE);

        abort_unless($survey instanceof Survey, 404);

        return $survey;
    }

    private function participantSession(Request $request): ParticipantSession
    {
        $session = $request->attributes->get(AttachParticipantSession::SESSION_ATTRIBUTE);

        abort_unless($session instanceof ParticipantSession, 404);

        return $session;
    }
}
