<?php

namespace App\Domain\Participation;

use App\Domain\Presence\PresenceService;
use App\Domain\Realtime\SafeBroadcaster;
use App\Domain\Stats\StatsService;
use App\Events\OpenTextReceived;
use App\Events\SurveyStatsUpdated;
use App\Models\ParticipantSession;
use App\Models\Question;
use App\Models\Response;
use App\Models\ResponseAnswer;
use App\Models\ResponseSelection;
use App\Models\Survey;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Registro de un envio de participante. El endpoint central del producto.
 *
 * ================= EL ORDEN DE LAS OPERACIONES ES EL DISENO =================
 * 1. Validar contra el esquema real (AnswerValidator).
 * 2. Comprobar duplicado -> si ya existe, devolver el recibo original.
 * 3. PERSISTIR en una transaccion.
 * 4. Y SOLO ENTONCES difundir, a traves de SafeBroadcaster.
 *
 * El paso 4 va despues y envuelto a proposito. D-02, regla innegociable: un
 * fallo de difusion JAMAS puede impedir guardar una respuesta. Si Reverb esta
 * caido, el envio ya esta en la base de datos y el dashboard lo recogera en el
 * siguiente ciclo de `wire:poll`. El participante nunca ve un error por un
 * problema que no es suyo.
 * ============================================================================
 */
class ParticipationService
{
    public function __construct(
        private readonly AnswerValidator $validator,
        private readonly StatsService $stats,
        private readonly PresenceService $presence,
        private readonly SafeBroadcaster $broadcaster,
    ) {}

    /**
     * @param  array<int, array>  $answers
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function submit(
        Survey $survey,
        ParticipantSession $session,
        string $submissionUuid,
        array $answers,
    ): SubmissionResult {
        /*
         * ANTI-DUPLICADO, CAPA 2 (ADR-0005): idempotencia por sesion.
         *
         * Se comprueba ANTES de validar. Un reintento tras un corte de red
         * trae exactamente el mismo cuerpo, y revalidarlo para despues
         * descartarlo seria trabajo inutil; pero sobre todo, si entretanto la
         * encuesta hubiese cambiado, el reintento podria fallar la validacion
         * y mostrar un error por un envio que YA ESTA GUARDADO.
         */
        $existing = $this->findExisting($survey, $session, $submissionUuid);

        if ($existing !== null) {
            return $this->receiptFor($existing, duplicate: true);
        }

        $validated = $this->validator->validate($survey, $answers);

        try {
            $response = $this->persist($survey, $session, $submissionUuid, $validated);
        } catch (QueryException $e) {
            /*
             * ANTI-DUPLICADO, CAPA 3: el arbitro es la BASE DE DATOS.
             *
             * Bajo concurrencia real (dos pulsaciones simultaneas, o el
             * reintento que se cruza con el original), ambas peticiones pasan
             * la comprobacion anterior y una de las dos pierde contra
             * UNIQUE(responses.participant_session_id) o
             * UNIQUE(survey_id, submission_uuid).
             *
             * La perdedora NO es un error: se convierte en la respuesta
             * idempotente. La aplicacion no intenta arbitrar algo que el motor
             * ya arbitro mejor.
             */
            if (! $this->isUniquenessViolation($e)) {
                throw $e;
            }

            $existing = $this->findExisting($survey, $session, $submissionUuid);

            if ($existing === null) {
                throw $e;
            }

            return $this->receiptFor($existing, duplicate: true);
        }

        $this->announce($survey, $response, $validated);

        return $this->receiptFor($response, duplicate: false);
    }

    // ------------------------------------------------------------ persistencia

    /**
     * Una sola transaccion para las cuatro escrituras (API.md §2.5).
     *
     * Deliberadamente CORTISIMA: SQLite tiene un unico escritor y el riesgo R3
     * (`database is locked`) crece con la duracion de la transaccion. Validar,
     * difundir y calcular metricas ocurren FUERA de ella.
     */
    private function persist(
        Survey $survey,
        ParticipantSession $session,
        string $submissionUuid,
        array $validated,
    ): Response {
        return DB::transaction(function () use ($survey, $session, $submissionUuid, $validated): Response {
            $now = Carbon::now();

            $response = Response::create([
                'survey_id' => $survey->id,
                'participant_session_id' => $session->id,
                'submission_uuid' => $submissionUuid,
                // RN-03: con que version se respondio. Un cambio de redaccion
                // posterior no debe reescribir la historia de este envio.
                'survey_version_number' => $survey->version_number,
                'completed_at' => $now,
            ]);

            foreach ($validated as $item) {
                /** @var Question $question */
                $question = $item['question'];

                $answer = ResponseAnswer::create([
                    'response_id' => $response->id,
                    'question_id' => $question->id,
                    // Texto EN CRUDO. Se escapa al renderizar, no al guardar.
                    'text_answer' => $item['text'],
                ]);

                foreach ($item['option_ids'] as $optionId) {
                    ResponseSelection::create([
                        'response_answer_id' => $answer->id,
                        'question_option_id' => $optionId,
                    ]);
                }
            }

            $session->forceFill([
                'submission_state' => ParticipantSession::STATE_COMPLETED,
                'completed_at' => $now,
                'last_seen_at' => $now,
            ])->save();

            return $response;
        });
    }

    /**
     * Busca un envio previo por CUALQUIERA de las dos claves de idempotencia.
     *
     * Las dos son necesarias y cubren casos distintos:
     *   · por sesion: la misma persona reenvia, con o sin el mismo uuid.
     *   · por submission_uuid: el reintento automatico del cliente tras un
     *     fallo de red, que mantiene el uuid constante entre intentos.
     */
    private function findExisting(
        Survey $survey,
        ParticipantSession $session,
        string $submissionUuid,
    ): ?Response {
        return Response::query()
            ->where('survey_id', $survey->id)
            ->where(function ($query) use ($session, $submissionUuid): void {
                $query->where('participant_session_id', $session->id)
                    ->orWhere('submission_uuid', $submissionUuid);
            })
            ->first();
    }

    private function isUniquenessViolation(QueryException $e): bool
    {
        // 23000/23505 son los SQLSTATE estandar de violacion de integridad en
        // SQLite, MySQL 8 y PostgreSQL. Sin comprobar textos de mensaje, que
        // divergen entre motores (D-04: nada especifico de un motor).
        return in_array((string) $e->getCode(), ['23000', '23505'], true);
    }

    // ------------------------------------------------------------- difusion

    /**
     * Difunde el cambio. TODO lo de aqui puede fallar sin consecuencias: el
     * envio ya esta persistido y confirmado.
     */
    private function announce(Survey $survey, Response $response, array $validated): void
    {
        if ($survey->public_id === null) {
            return;
        }

        // El evento lleva una instantanea leida de la BASE DE DATOS, no un
        // contador incrementado en memoria. Asi el payload y lo que el
        // dashboard reconsulta no pueden divergir (D-02).
        $this->broadcaster->dispatch(
            new SurveyStatsUpdated($this->stats->snapshot($survey))
        );

        foreach ($validated as $item) {
            /** @var Question $question */
            $question = $item['question'];

            if (! $question->isFreeText() || $item['text'] === null || trim($item['text']) === '') {
                continue;
            }

            // Sin identificador de sesion en la carga util: el admin no puede
            // ligar este texto con el resto de respuestas de la misma persona
            // desde el feed en vivo (ARCHITECTURE.md §4.3).
            $this->broadcaster->dispatch(new OpenTextReceived(
                surveyPublicId: $survey->public_id,
                questionId: $question->id,
                text: $item['text'],
                receivedAt: Carbon::instance($response->completed_at),
            ));
        }
    }

    // --------------------------------------------------------------- recibos

    private function receiptFor(Response $response, bool $duplicate): SubmissionResult
    {
        return new SubmissionResult(
            response: $response,
            duplicate: $duplicate,
            receiptId: $this->receiptId($response),
            completedAt: Carbon::instance($response->completed_at),
        );
    }

    /**
     * Recibo visible para el participante.
     *
     * Es un hash corto derivado del id y del uuid del envio, NO el id interno
     * ni el token (API.md §2.5). Sirve para que alguien pueda decir "mi recibo
     * es R-7F3A2B9C" al pedir ayuda, sin que ese dato permita enumerar
     * respuestas ni deducir cuantas van.
     */
    private function receiptId(Response $response): string
    {
        $digest = hash('sha256', $response->id . '|' . $response->submission_uuid);

        return 'R-' . strtoupper(substr($digest, 0, 8));
    }

    /**
     * Heartbeat de una sesion, con difusion de presencia.
     *
     * El evento solo se emite si el recuento de conectadas PUDO cambiar: con
     * 300 sesiones latiendo cada 20 s serian 15 eventos por segundo si se
     * emitiera siempre, y el dashboard reconsultaria la base de datos 15 veces
     * por segundo para pintar el mismo numero.
     */
    public function heartbeat(Survey $survey, ParticipantSession $session): void
    {
        $countMayHaveChanged = $this->presence->touch($session);

        if ($countMayHaveChanged && $survey->public_id !== null) {
            $this->broadcaster->dispatch(
                new SurveyStatsUpdated($this->stats->snapshot($survey))
            );
        }
    }
}
