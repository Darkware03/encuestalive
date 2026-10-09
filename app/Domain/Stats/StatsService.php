<?php

namespace App\Domain\Stats;

use App\Models\ParticipantSession;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Response;
use App\Models\ResponseAnswer;
use App\Models\ResponseSelection;
use App\Models\Survey;
use Illuminate\Support\Carbon;

/**
 * StatsService — FUENTE UNICA de las metricas de EncuestaLive (D-07).
 *
 * ================== REGLA DURA, DE D-02 Y D-07 ==================
 * Ningun componente calcula metricas por su cuenta. El dashboard lee SIEMPRE
 * de la base de datos a traves de este servicio. El WebSocket solo indica
 * CUANDO refrescar, nunca QUE dato mostrar.
 *
 * Consecuencia practica, y es la razon de ser del diseno: los contadores son
 * IDENTICOS en modo Reverb y en modo `wire:poll`, y no hay deriva posible tras
 * una reconexion o un evento perdido. Reconciliar es una sola consulta, no un
 * algoritmo de reproduccion de eventos (ARCHITECTURE.md §3.3, CA-10, CA-15).
 * ================================================================
 *
 * Formulas normativas implementadas aqui y en ningun otro sitio:
 *
 *   D-07   tasa_respuesta = completadas / sesiones_iniciadas   (ambas ACUMULADAS)
 *   §8.4   pendientes     = solo sobre sesiones CONECTADAS
 *   D-06   multiple       = selecciones / participantes_en_la_pregunta  (puede > 100 %)
 *   D-12   conectada      = last_seen_at > ahora - 60 s
 *
 * NOTA DE CONTRATO: API.md §5.2 describe `response_rate` como
 * `completed_responses / participated_sessions`. D-07 fija el denominador en
 * `sesiones_iniciadas` y DECISIONS.md prevalece sobre cualquier otro documento.
 * Se implementa D-07. La discrepancia queda reportada, no resuelta en silencio.
 *
 * Este servicio NO conoce HTTP: ni `request()`, ni `session()`, ni cookies
 * (ARCHITECTURE.md §2: el dominio nunca conoce la capa HTTP).
 */
class StatsService
{
    /**
     * Instantanea completa. Es la unica operacion que el dashboard necesita,
     * tanto al montar como en cada refresco, venga de Echo o de `wire:poll`.
     */
    public function snapshot(Survey $survey, ?Carbon $now = null): StatsSnapshot
    {
        $now = $now ? $now->copy() : Carbon::now();

        $presence = $this->presence($survey, $now);
        $accumulated = $this->accumulated($survey, $presence['completed_live']);

        return new StatsSnapshot(
            surveyPublicId: (string) ($survey->public_id ?? ''),
            status: $survey->status,
            sessionsStarted: $accumulated['sessions_started'],
            participatedSessions: $accumulated['participated'],
            completedResponses: $accumulated['completed'],
            connectedSessions: $presence['connected'],
            pendingSessions: $presence['pending'],
            connectedCompleted: $presence['connected_completed'],
            responseRate: $this->responseRate(
                $accumulated['completed'],
                $accumulated['sessions_started'],
            ),
            questions: $this->questionStats($survey),
            generatedAt: $now,
            fromSnapshot: $accumulated['from_snapshot'],
        );
    }

    // ------------------------------------------------------------- presencia

    /**
     * Presencia (D-12, ADR-0004). Metrica INSTANTANEA, derivada de
     * `last_seen_at`; no hay contador almacenado que reconciliar.
     *
     * Por eso CA-15 se cumple por construccion: tras reiniciar la aplicacion o
     * Reverb, la primera consulta ya devuelve el valor correcto sin ninguna
     * accion de recuperacion. No existe estado en memoria que perder.
     *
     * @return array{connected:int, pending:int, connected_completed:int, completed_live:int}
     */
    private function presence(Survey $survey, Carbon $now): array
    {
        $base = ParticipantSession::query()->where('survey_id', $survey->id);

        $connected = (clone $base)->connected($now)->count();

        $connectedCompleted = (clone $base)
            ->connected($now)
            ->where('submission_state', ParticipantSession::STATE_COMPLETED)
            ->count();

        return [
            'connected' => $connected,
            // PRD §8.4: pendientes = conectadas AHORA que aun no completaron.
            // Se resta en lugar de consultar otra vez para que la identidad
            // `pendientes + conectadas_que_completaron = conectadas` sea cierta
            // POR CONSTRUCCION y no por coincidencia de dos consultas que
            // podrian correr en instantes distintos.
            'pending' => $connected - $connectedCompleted,
            'connected_completed' => $connectedCompleted,
            'completed_live' => (clone $base)
                ->where('submission_state', ParticipantSession::STATE_COMPLETED)
                ->count(),
        ];
    }

    // ------------------------------------------------------------ acumulados

    /**
     * Metricas acumuladas (PRD §8.1-§8.3). Nunca disminuyen al desconectarse
     * la gente: ese es justamente el motivo de que D-07 use acumulados.
     *
     * `snapshot_*` tiene PREFERENCIA en encuestas cerradas o archivadas que ya
     * lo tengan escrito. Sin eso, la purga de sesiones de RN-09.5 haria que la
     * tasa de una encuesta de hace seis meses pasara de 64,3 % a indefinida el
     * dia que desaparece el denominador (DATABASE.md §3.2).
     *
     * @return array{sessions_started:int, participated:int, completed:int, from_snapshot:bool}
     */
    private function accumulated(Survey $survey, int $completedSessions): array
    {
        $isFinished = in_array(
            $survey->status,
            [Survey::STATUS_CLOSED, Survey::STATUS_ARCHIVED],
            true,
        );

        if ($isFinished && $survey->snapshot_sessions_started !== null) {
            return [
                'sessions_started' => (int) $survey->snapshot_sessions_started,
                'participated' => (int) $survey->snapshot_participated,
                'completed' => (int) $survey->snapshot_completed,
                'from_snapshot' => true,
            ];
        }

        $base = ParticipantSession::query()->where('survey_id', $survey->id);

        $sessionsStarted = (clone $base)->count();
        $participated = (clone $base)->participated()->count();

        /*
         * `completadas` se cuenta sobre `responses`, que es la definicion
         * literal del PRD §8.3 ("envios finalizados y persistidos"). Coincide
         * con las sesiones completadas por el UNIQUE de
         * responses.participant_session_id, salvo tras la purga de RN-09.5,
         * donde el envio sobrevive desvinculado y la sesion ya no existe.
         *
         * Las dos cotas inferiores mantienen cierta la invariante
         * `completadas <= participaron <= sesiones_iniciadas` tambien en ese
         * caso. Preferir el maximo y no el minimo es deliberado: perder de
         * vista un envio realmente persistido seria peor que sobreestimar el
         * denominador.
         */
        $completed = Response::query()->where('survey_id', $survey->id)->count();
        $completed = max($completed, $completedSessions);
        $participated = max($participated, $completed);
        $sessionsStarted = max($sessionsStarted, $participated);

        return [
            'sessions_started' => $sessionsStarted,
            'participated' => $participated,
            'completed' => $completed,
            'from_snapshot' => false,
        ];
    }

    /**
     * D-07, formula unica y normativa.
     * Denominador 0 -> 0.0 en el dato, y la interfaz pinta "—" apoyandose en
     * StatsSnapshot::responseRatePercent(), que devuelve null. Nunca una
     * division por cero y nunca un "0 %" que afirmaria algo falso.
     */
    private function responseRate(int $completed, int $sessionsStarted): float
    {
        if ($sessionsStarted <= 0) {
            return 0.0;
        }

        return min(1.0, $completed / $sessionsStarted);
    }

    // -------------------------------------------------------------- por pregunta

    /**
     * Agregados por pregunta. Tres consultas en total, no tres por pregunta:
     * el dashboard se refresca cada 5 s y un N+1 aqui se nota.
     *
     * @return array<int, QuestionStats>
     */
    public function questionStats(Survey $survey): array
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, Question> $questions */
        $questions = Question::query()
            ->where('survey_id', $survey->id)
            ->with('options')
            ->orderBy('position')
            ->get();

        if ($questions->isEmpty()) {
            return [];
        }

        $questionIds = $questions->pluck('id')->all();

        // Respuestas completadas, para excluir cualquier envio a medias.
        $completedResponseIds = Response::query()
            ->where('survey_id', $survey->id)
            ->pluck('id');

        if ($completedResponseIds->isEmpty()) {
            return $questions->map(fn (Question $q) => new QuestionStats(
                questionId: $q->id,
                type: $q->type,
                prompt: $q->prompt,
                isRequired: (bool) $q->is_required,
                answeredCount: 0,
                totalSelections: 0,
                skippedCount: 0,
                options: $this->emptyOptions($q),
                textAnswersCount: 0,
            ))->all();
        }

        $totalCompleted = $completedResponseIds->count();

        // 1. Cabeceras de respuesta por pregunta.
        $answers = ResponseAnswer::query()
            ->whereIn('question_id', $questionIds)
            ->whereIn('response_id', $completedResponseIds)
            ->get(['id', 'question_id', 'text_answer']);

        $answerIdsByQuestion = [];
        $textCountByQuestion = [];

        foreach ($answers as $answer) {
            $answerIdsByQuestion[$answer->question_id][] = $answer->id;

            if (is_string($answer->text_answer) && trim($answer->text_answer) !== '') {
                $textCountByQuestion[$answer->question_id] =
                    ($textCountByQuestion[$answer->question_id] ?? 0) + 1;
            }
        }

        // 2. Marcas por opcion, en una sola consulta agrupada
        //    (indice response_selections.question_option_id).
        $allAnswerIds = $answers->pluck('id')->all();

        $tally = [];
        if ($allAnswerIds !== []) {
            $rows = ResponseSelection::query()
                ->whereIn('response_answer_id', $allAnswerIds)
                ->groupBy('question_option_id')
                ->selectRaw('question_option_id, COUNT(*) as total')
                ->get();

            foreach ($rows as $row) {
                $tally[(int) $row->question_option_id] = (int) $row->total;
            }
        }

        return $questions->map(function (Question $question) use (
            $answerIdsByQuestion,
            $textCountByQuestion,
            $tally,
            $totalCompleted,
        ) {
            $answerIds = $answerIdsByQuestion[$question->id] ?? [];

            /*
             * `answeredCount` es el DENOMINADOR de D-06 base A: envios
             * completados con al menos una respuesta a esta pregunta. Las
             * preguntas opcionales sin responder no generan fila (RN-13), asi
             * que contar cabeceras ya excluye las ausencias.
             */
            $answeredCount = count($answerIds);

            $options = [];
            $totalSelections = 0;

            if ($question->isChoiceBased()) {
                foreach ($question->options as $option) {
                    $totalSelections += $tally[$option->id] ?? 0;
                }

                foreach ($question->options as $option) {
                    $count = $tally[$option->id] ?? 0;

                    $options[] = new OptionStats(
                        optionId: $option->id,
                        label: $option->label,
                        count: $count,
                        // D-06 base A. PUEDE superar 1.0 en multiple: no se
                        // recorta, porque recortarlo seria falsear el dato.
                        percentageOfParticipants: $answeredCount > 0
                            ? $count / $answeredCount
                            : 0.0,
                        // Base B: la suma da exactamente 1.0.
                        percentageOfSelections: $totalSelections > 0
                            ? $count / $totalSelections
                            : 0.0,
                    );
                }
            }

            return new QuestionStats(
                questionId: $question->id,
                type: $question->type,
                prompt: $question->prompt,
                isRequired: (bool) $question->is_required,
                answeredCount: $answeredCount,
                totalSelections: $totalSelections,
                skippedCount: max(0, $totalCompleted - $answeredCount),
                options: $options,
                textAnswersCount: $textCountByQuestion[$question->id] ?? 0,
            );
        })->all();
    }

    /** @return array<int, OptionStats> */
    private function emptyOptions(Question $question): array
    {
        if (! $question->isChoiceBased()) {
            return [];
        }

        return $question->options
            ->map(fn (QuestionOption $o) => new OptionStats($o->id, $o->label, 0, 0.0, 0.0))
            ->all();
    }

    // ------------------------------------------------------------ texto libre

    /**
     * Textos libres de una encuesta, los mas recientes primero.
     *
     * NO devuelve el identificador de la sesion que los escribio: el admin no
     * debe poder ligar un texto con el resto de respuestas de la misma persona
     * desde el feed (ARCHITECTURE.md §4.3, ADR-0005).
     *
     * El texto va EN CRUDO. Sanearlo aqui destruiria el dato original y daria
     * una falsa sensacion de seguridad; el escapado es responsabilidad del
     * renderizado, que en Blade es `{{ }}` por defecto.
     *
     * @return array<int, array{question_id:int, prompt:string, text:string, received_at:Carbon}>
     */
    public function openTextAnswers(Survey $survey, int $limit = 50): array
    {
        return ResponseAnswer::query()
            ->join('responses', 'responses.id', '=', 'response_answers.response_id')
            ->join('questions', 'questions.id', '=', 'response_answers.question_id')
            ->where('responses.survey_id', $survey->id)
            ->where('questions.type', Question::TYPE_FREE_TEXT)
            ->whereNotNull('response_answers.text_answer')
            ->where('response_answers.text_answer', '!=', '')
            ->orderByDesc('responses.completed_at')
            ->limit($limit)
            ->get([
                'response_answers.question_id',
                'response_answers.text_answer',
                'questions.prompt',
                'responses.completed_at',
            ])
            ->map(fn ($row) => [
                'question_id' => (int) $row->question_id,
                'prompt' => (string) $row->prompt,
                'text' => (string) $row->text_answer,
                'received_at' => Carbon::parse($row->completed_at),
            ])
            ->all();
    }

    /**
     * Congela las metricas finales al cerrar (RN-02.5). Se llama UNA vez en la
     * vida de la encuesta, desde SurveyService::close().
     */
    public function writeClosingSnapshot(Survey $survey): void
    {
        $stats = $this->snapshot($survey);

        $survey->forceFill([
            'snapshot_sessions_started' => $stats->sessionsStarted,
            'snapshot_participated' => $stats->participatedSessions,
            'snapshot_completed' => $stats->completedResponses,
            'snapshot_response_rate' => $stats->responseRatePercent() ?? 0.0,
        ])->save();
    }
}
