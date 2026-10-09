<?php

namespace Tests\Feature\Stats;

use App\Domain\Stats\QuestionStats;
use App\Domain\Stats\StatsService;
use App\Models\ParticipantSession;
use App\Models\Question;
use App\Models\Response;
use App\Models\ResponseAnswer;
use App\Models\ResponseSelection;
use App\Models\Survey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsSurveys;
use Tests\TestCase;

/**
 * StatsService — CA-10 (contadores consistentes) y las formulas normativas
 * D-06, D-07 y D-12.
 *
 * Los numeros se construyen a mano para poder afirmar el resultado EXACTO. Una
 * prueba de metricas que solo comprueba "es mayor que cero" no prueba la
 * formula, que es justo lo unico que hay que probar aqui.
 */
class StatsServiceTest extends TestCase
{
    use RefreshDatabase, BuildsSurveys;

    private function stats(): StatsService
    {
        return app(StatsService::class);
    }

    /**
     * Monta una situacion de presencia controlada.
     *
     * @return Survey
     */
    private function surveyWithPresence(): Survey
    {
        $survey = Survey::factory()->published()->create();

        // 10 sesiones iniciadas en total.
        //   · 4 conectadas y completadas
        //   · 3 conectadas sin completar   -> pendientes
        //   · 2 desconectadas y completadas
        //   · 1 desconectada sin completar -> abandonada
        $make = function (int $count, bool $connected, bool $completed) use ($survey): void {
            for ($i = 0; $i < $count; $i++) {
                $session = ParticipantSession::create([
                    'survey_id' => $survey->id,
                    'token_hash' => hash('sha256', uniqid('', true)),
                    'submission_state' => $completed
                        ? ParticipantSession::STATE_COMPLETED
                        : ParticipantSession::STATE_IN_PROGRESS,
                    'first_seen_at' => now()->subMinutes(30),
                    // D-12: la ventana es de 60 s. 10 s dentro, 5 min fuera.
                    'last_seen_at' => $connected ? now()->subSeconds(10) : now()->subMinutes(5),
                    'completed_at' => $completed ? now()->subMinutes(2) : null,
                ]);

                if ($completed) {
                    Response::create([
                        'survey_id' => $survey->id,
                        'participant_session_id' => $session->id,
                        'submission_uuid' => (string) \Illuminate\Support\Str::uuid(),
                        'survey_version_number' => 1,
                        'completed_at' => now()->subMinutes(2),
                    ]);
                }
            }
        };

        $make(4, connected: true, completed: true);
        $make(3, connected: true, completed: false);
        $make(2, connected: false, completed: true);
        $make(1, connected: false, completed: false);

        return $survey;
    }

    // ----------------------------------------------------- D-07 / §8.4

    public function test_the_counters_match_the_normative_formulas(): void
    {
        $snapshot = $this->stats()->snapshot($this->surveyWithPresence());

        $this->assertSame(10, $snapshot->sessionsStarted);
        $this->assertSame(10, $snapshot->participatedSessions);
        $this->assertSame(6, $snapshot->completedResponses);

        // D-12: conectadas = last_seen_at dentro de los ultimos 60 s.
        $this->assertSame(7, $snapshot->connectedSessions);

        // PRD §8.4: pendientes SOLO sobre conectadas.
        $this->assertSame(3, $snapshot->pendingSessions);
        $this->assertSame(4, $snapshot->connectedCompleted);

        // D-07: completadas / sesiones_iniciadas = 6/10.
        $this->assertSame(0.6, round($snapshot->responseRate, 3));
        $this->assertSame(60.0, $snapshot->responseRatePercent());
    }

    /**
     * Las cuatro invariantes de PRD §8.7 (CA-10). Se comprueban sobre el DTO,
     * que es el mismo objeto que el dashboard pinta.
     */
    public function test_the_prd_invariants_hold(): void
    {
        $snapshot = $this->stats()->snapshot($this->surveyWithPresence());

        $this->assertSame([], $snapshot->invariantViolations());

        $this->assertLessThanOrEqual($snapshot->participatedSessions, $snapshot->completedResponses);
        $this->assertLessThanOrEqual($snapshot->sessionsStarted, $snapshot->participatedSessions);
        $this->assertLessThanOrEqual($snapshot->sessionsStarted, $snapshot->connectedSessions);
        $this->assertSame(
            $snapshot->connectedSessions,
            $snapshot->pendingSessions + $snapshot->connectedCompleted,
        );
    }

    /**
     * Denominador 0 -> "—", nunca 0 % ni division por cero (PRD §8.5).
     * Un 0 % afirmaria que nadie respondio cuando en realidad nadie ha entrado.
     */
    public function test_the_response_rate_is_undefined_when_nobody_started(): void
    {
        $survey = Survey::factory()->published()->create();

        $snapshot = $this->stats()->snapshot($survey);

        $this->assertSame(0, $snapshot->sessionsStarted);
        $this->assertNull($snapshot->responseRatePercent());
        $this->assertSame([], $snapshot->invariantViolations());
    }

    /**
     * D-07: los acumulados NO bajan al desconectarse la gente. Esa es
     * literalmente la razon de usar `sesiones_iniciadas` como denominador.
     */
    public function test_disconnecting_does_not_lower_the_response_rate(): void
    {
        $survey = $this->surveyWithPresence();

        $before = $this->stats()->snapshot($survey);

        // Todo el mundo se va de la sala.
        ParticipantSession::query()->update(['last_seen_at' => now()->subHour()]);

        $after = $this->stats()->snapshot($survey);

        $this->assertSame(0, $after->connectedSessions);
        $this->assertSame(0, $after->pendingSessions);

        // Los acumulados y la tasa, intactos.
        $this->assertSame($before->sessionsStarted, $after->sessionsStarted);
        $this->assertSame($before->completedResponses, $after->completedResponses);
        $this->assertSame($before->responseRate, $after->responseRate);
    }

    /**
     * CA-15: no hay estado en memoria. Dos instancias nuevas del servicio
     * sobre la misma base de datos dan el mismo resultado, que es lo que
     * ocurre tras reiniciar la aplicacion.
     */
    public function test_counters_are_rebuilt_from_the_database_with_no_memory_state(): void
    {
        $survey = $this->surveyWithPresence();

        $a = (new StatsService())->snapshot($survey);
        $b = (new StatsService())->snapshot($survey->fresh());

        $this->assertSame($a->sessionsStarted, $b->sessionsStarted);
        $this->assertSame($a->completedResponses, $b->completedResponses);
        $this->assertSame($a->connectedSessions, $b->connectedSessions);
        $this->assertSame($a->responseRate, $b->responseRate);
    }

    // ------------------------------------------------------ D-06 bases

    /**
     * D-06: en seleccion multiple la base por defecto es SOBRE PARTICIPANTES
     * y el porcentaje PUEDE SUPERAR EL 100 %.
     */
    public function test_multiple_choice_percentages_use_participants_and_may_exceed_100(): void
    {
        $survey = Survey::factory()->published()->create();

        $question = Question::factory()->create([
            'survey_id' => $survey->id,
            'type' => Question::TYPE_MULTIPLE_CHOICE,
            'prompt' => 'Que temas?',
            'position' => 1,
            'min_selections' => 1,
            'max_selections' => 3,
        ]);

        [$a, $b, $c] = $this->addOptions($question, ['A', 'B', 'C']);

        // 2 participantes; AMBOS marcan las tres opciones.
        for ($i = 0; $i < 2; $i++) {
            $session = ParticipantSession::create([
                'survey_id' => $survey->id,
                'token_hash' => hash('sha256', uniqid('', true)),
                'submission_state' => ParticipantSession::STATE_COMPLETED,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'completed_at' => now(),
            ]);

            $response = Response::create([
                'survey_id' => $survey->id,
                'participant_session_id' => $session->id,
                'submission_uuid' => (string) \Illuminate\Support\Str::uuid(),
                'survey_version_number' => 1,
                'completed_at' => now(),
            ]);

            $answer = ResponseAnswer::create([
                'response_id' => $response->id,
                'question_id' => $question->id,
            ]);

            foreach ([$a, $b, $c] as $option) {
                ResponseSelection::create([
                    'response_answer_id' => $answer->id,
                    'question_option_id' => $option->id,
                ]);
            }
        }

        $stats = $this->stats()->snapshot($survey)->question($question->id);

        $this->assertSame(QuestionStats::BASIS_PARTICIPANTS, $stats->basis());
        $this->assertSame(2, $stats->answeredCount);
        $this->assertSame(6, $stats->totalSelections);

        // Base A: 2 de 2 participantes marcaron cada opcion -> 100 % cada una.
        // La SUMA es 300 %, y eso es CORRECTO, no un fallo.
        $suma = 0.0;
        foreach ($stats->options as $option) {
            $this->assertSame(100.0, $option->percentFor(QuestionStats::BASIS_PARTICIPANTS));
            $suma += $option->percentFor(QuestionStats::BASIS_PARTICIPANTS);
        }
        $this->assertSame(300.0, $suma);

        // Base B (la del CSV): 1/3 cada una, suma exactamente 100 %.
        $sumaB = 0.0;
        foreach ($stats->options as $option) {
            $sumaB += $option->percentFor(QuestionStats::BASIS_SELECTIONS);
        }
        $this->assertEqualsWithDelta(100.0, $sumaB, 0.2);
    }

    /**
     * D-06 obliga a DECLARAR el denominador en pantalla. El DTO lo lleva
     * pegado al dato para que sea dificil pintarlo sin su explicacion.
     */
    public function test_the_denominator_label_is_always_available(): void
    {
        $owner = $this->owner();
        $built = $this->publishedSurveyWithAllTypes($owner);

        $this->openSurvey($built['survey']);
        $this->submitAs($built['survey'], $this->validAnswers($built))->assertOk();

        $snapshot = $this->stats()->snapshot($built['survey']->fresh());

        $multiple = $snapshot->question($built['multiple']->id);
        $this->assertStringContainsString('participantes', $multiple->denominatorLabel());
        $this->assertStringContainsString('superar el 100', $multiple->denominatorLabel());

        $single = $snapshot->question($built['single']->id);
        $this->assertStringContainsString('respuestas a esta pregunta', $single->denominatorLabel());
    }

    public function test_single_choice_percentages_sum_to_one_hundred(): void
    {
        $owner = $this->owner();
        $built = $this->publishedSurveyWithAllTypes($owner);

        foreach (range(1, 3) as $i) {
            $this->newParticipant();
            $this->openSurvey($built['survey']);
            $this->submitAs($built['survey'], $this->validAnswers($built))->assertOk();
        }

        $single = $this->stats()->snapshot($built['survey']->fresh())
            ->question($built['single']->id);

        $this->assertSame(3, $single->answeredCount);

        $suma = 0.0;
        foreach ($single->options as $option) {
            $suma += $option->percentFor($single->basis());
        }

        $this->assertEqualsWithDelta(100.0, $suma, 0.2);
    }

    // ------------------------------------------------ instantanea al cerrar

    /**
     * DATABASE.md §3.2: tras la purga de sesiones, el historico NO puede
     * cambiar. Las columnas snapshot_* son las que lo sostienen.
     */
    public function test_a_closed_survey_keeps_its_history_after_the_session_purge(): void
    {
        $owner = $this->owner();
        $survey = $this->surveyWithPresence();
        $survey->forceFill(['created_by_user_id' => $owner->id])->save();

        app(\App\Domain\Surveys\SurveyService::class)->close($owner, $survey);

        $before = $this->stats()->snapshot($survey->fresh());
        $this->assertSame(10, $before->sessionsStarted);
        $this->assertSame(60.0, $before->responseRatePercent());

        // Llega la purga de RN-09.5: las sesiones desaparecen y los envios
        // quedan desvinculados (SET NULL).
        $survey->participantSessions()->delete();

        $after = $this->stats()->snapshot($survey->fresh());

        $this->assertTrue($after->fromSnapshot);
        $this->assertSame(10, $after->sessionsStarted);
        $this->assertSame(6, $after->completedResponses);
        $this->assertSame(60.0, $after->responseRatePercent());

        // Y las respuestas siguen ahi, solo que sin dueno identificable.
        $this->assertSame(6, Response::query()->where('survey_id', $survey->id)->count());
    }

    public function test_the_presence_window_follows_d12(): void
    {
        $presence = app(\App\Domain\Presence\PresenceService::class);

        $this->assertSame(20, $presence->heartbeatIntervalSeconds());
        $this->assertSame(60, $presence->expiryWindowSeconds());
    }

    /**
     * Una sesion justo dentro y otra justo fuera de la ventana de 60 s.
     * Es donde un error de signo o de unidad se manifestaria.
     */
    public function test_the_expiry_boundary_is_sixty_seconds(): void
    {
        $survey = Survey::factory()->published()->create();

        foreach ([55 => true, 65 => false] as $seconds => $shouldCount) {
            ParticipantSession::create([
                'survey_id' => $survey->id,
                'token_hash' => hash('sha256', 'borde-' . $seconds),
                'submission_state' => ParticipantSession::STATE_IN_PROGRESS,
                'first_seen_at' => now()->subMinutes(10),
                'last_seen_at' => now()->subSeconds($seconds),
            ]);
        }

        $this->assertSame(1, $this->stats()->snapshot($survey)->connectedSessions);
    }
}
