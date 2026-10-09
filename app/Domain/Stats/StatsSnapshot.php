<?php

namespace App\Domain\Stats;

use Illuminate\Support\Carbon;

/**
 * Instantanea inmutable de las metricas de una encuesta.
 *
 * Es un DTO deliberadamente tonto: no calcula nada. Todo el calculo vive en
 * StatsService, que es la UNICA fuente autorizada de estos numeros (D-07).
 *
 * Lleva SIEMPRE `generatedAt`: el PRD §8.7 exige que cada carga util de
 * metricas lleve marca temporal del servidor, y que la interfaz muestre
 * "Actualizado hh:mm:ss". Un numero sin su hora no se puede sostener.
 */
final class StatsSnapshot
{
    /**
     * @param  array<int, QuestionStats>  $questions
     */
    public function __construct(
        public readonly string $surveyPublicId,
        public readonly string $status,
        public readonly int $sessionsStarted,
        public readonly int $participatedSessions,
        public readonly int $completedResponses,
        public readonly int $connectedSessions,
        public readonly int $pendingSessions,
        public readonly int $connectedCompleted,
        public readonly float $responseRate,
        public readonly array $questions,
        public readonly Carbon $generatedAt,
        /** True cuando los acumulados proceden de snapshot_* y no de filas vivas. */
        public readonly bool $fromSnapshot = false,
    ) {}

    /**
     * Tasa como porcentaje con una cifra decimal (PRD §8.5).
     * `null` cuando el denominador es 0: la interfaz debe pintar "—", jamas
     * "0 %", que afirmaria que nadie respondio cuando nadie ha entrado aun.
     */
    public function responseRatePercent(): ?float
    {
        if ($this->sessionsStarted === 0) {
            return null;
        }

        return round($this->responseRate * 100, 1);
    }

    /**
     * Invariantes de PRD §8.7 (CA-10). Se comprueban, no se suponen.
     *
     * @return array<int, string> lista de invariantes violadas; vacia = correcto
     */
    public function invariantViolations(): array
    {
        $violations = [];

        if (! ($this->completedResponses <= $this->participatedSessions
            && $this->participatedSessions <= $this->sessionsStarted)) {
            $violations[] = 'completadas <= participaron <= sesiones_iniciadas';
        }

        if ($this->connectedSessions > $this->sessionsStarted) {
            $violations[] = 'conectadas <= sesiones_iniciadas';
        }

        if ($this->pendingSessions + $this->connectedCompleted !== $this->connectedSessions) {
            $violations[] = 'pendientes + conectadas_que_completaron = conectadas';
        }

        if ($this->responseRate < 0.0 || $this->responseRate > 1.0) {
            $violations[] = '0 <= tasa_respuesta <= 100';
        }

        return $violations;
    }

    /**
     * Carga util de `stats.updated` (API.md §5.2). CANAL PRIVADO.
     *
     * Array literal, nunca un modelo serializado (ARCHITECTURE.md §4.4). Aqui
     * no viaja ningun token, ninguna IP y ningun id de sesion: solo agregados
     * y los ids de pregunta y opcion, que no son secretos.
     */
    public function toBroadcastArray(): array
    {
        return [
            'survey_public_id' => $this->surveyPublicId,
            'status' => $this->status,
            'stats' => [
                'connected_sessions' => $this->connectedSessions,
                'participated_sessions' => $this->participatedSessions,
                'completed_responses' => $this->completedResponses,
                'pending_sessions' => $this->pendingSessions,
                'response_rate' => round($this->responseRate, 3),
                'questions' => array_map(
                    fn (QuestionStats $q) => $q->toBroadcastArray(),
                    $this->questions,
                ),
            ],
            'generated_at' => $this->generatedAt->toIso8601ZuluString(),
        ];
    }

    public function question(int $questionId): ?QuestionStats
    {
        foreach ($this->questions as $question) {
            if ($question->questionId === $questionId) {
                return $question;
            }
        }

        return null;
    }
}
