<?php

namespace Tests\Feature\Database;

use App\Models\AdminAuditEvent;
use App\Models\ParticipantSession;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Response;
use App\Models\ResponseAnswer;
use App\Models\ResponseSelection;
use App\Models\SecurityEvent;
use App\Models\Survey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Politicas ON DELETE. Cada una se ejerce de verdad, no se infiere del DDL.
 */
class CascadeAndRetentionTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:Survey,1:ParticipantSession,2:Response,3:ResponseAnswer,4:ResponseSelection} */
    private function fullSurvey(): array
    {
        $survey = Survey::factory()->published()->create();
        $question = Question::factory()->singleChoice()->create(['survey_id' => $survey->id]);
        $option = QuestionOption::factory()->create(['question_id' => $question->id]);
        $session = ParticipantSession::factory()->completed()->create(['survey_id' => $survey->id]);
        $response = Response::factory()->forSession($session)->create();
        $answer = ResponseAnswer::factory()->create([
            'response_id' => $response->id,
            'question_id' => $question->id,
        ]);
        $selection = ResponseSelection::create([
            'response_answer_id' => $answer->id,
            'question_option_id' => $option->id,
        ]);

        return [$survey, $session, $response, $answer, $selection];
    }

    /**
     * Eliminar una encuesta (solo por consola, RN-10.7 / T-13) se lleva TODO el
     * agregado de participacion. No deben quedar restos huerfanos.
     */
    public function test_deleting_a_survey_cascades_the_whole_aggregate(): void
    {
        [$survey, $session, $response, $answer, $selection] = $this->fullSurvey();

        $survey->delete();

        $this->assertDatabaseMissing('questions', ['survey_id' => $survey->id]);
        $this->assertDatabaseMissing('participant_sessions', ['id' => $session->id]);
        $this->assertDatabaseMissing('responses', ['id' => $response->id]);
        $this->assertDatabaseMissing('response_answers', ['id' => $answer->id]);
        $this->assertDatabaseMissing('response_selections', ['id' => $selection->id]);
        $this->assertSame(0, QuestionOption::count());
    }

    /**
     * RN-09.5: la purga de metadatos de sesion DESVINCULA el envio, no lo borra.
     * Es la razon de que responses.participant_session_id sea SET NULL.
     */
    public function test_purging_a_session_unlinks_the_response_but_keeps_it(): void
    {
        [, $session, $response, $answer] = $this->fullSurvey();

        $session->delete();

        $this->assertDatabaseMissing('participant_sessions', ['id' => $session->id]);
        $this->assertDatabaseHas('responses', [
            'id' => $response->id,
            'participant_session_id' => null,
        ]);
        $this->assertDatabaseHas('response_answers', ['id' => $answer->id]);
        $this->assertSame(1, Response::unlinked()->count());
    }

    /**
     * Tras la purga, UNIQUE(participant_session_id) debe seguir admitiendo
     * multiples NULL (comportamiento comun a SQLite y a MySQL 8).
     */
    public function test_several_unlinked_responses_can_coexist(): void
    {
        $survey = Survey::factory()->published()->create();

        Response::factory()->count(3)->create([
            'survey_id' => $survey->id,
            'participant_session_id' => null,
        ]);

        $this->assertSame(3, Response::unlinked()->count());
    }

    /** Borrar una cuenta administrativa jamas debe borrar encuestas ni respuestas. */
    public function test_deleting_a_user_preserves_surveys(): void
    {
        $user = User::factory()->create();
        $survey = Survey::factory()->published()->create(['created_by_user_id' => $user->id]);
        Response::factory()->create(['survey_id' => $survey->id]);

        $user->delete();

        $this->assertDatabaseHas('surveys', [
            'id' => $survey->id,
            'created_by_user_id' => null,
        ]);
        $this->assertSame(1, Response::where('survey_id', $survey->id)->count());
    }

    /** El rastro de auditoria sobrevive a la eliminacion de la encuesta. */
    public function test_audit_trail_survives_survey_deletion(): void
    {
        $survey = Survey::factory()->published()->create();
        $audit = AdminAuditEvent::factory()->create([
            'survey_id' => $survey->id,
            'survey_title_snapshot' => $survey->title,
            'action' => AdminAuditEvent::ACTION_PUBLISHED,
        ]);

        $survey->delete();

        $this->assertDatabaseHas('admin_audit_events', [
            'id' => $audit->id,
            'survey_id' => null,
            'survey_title_snapshot' => $survey->title,
        ]);
    }

    /**
     * La isla B es indiferente a la isla A: borrar la encuesta NO borra sus
     * registros de seguridad, porque no hay clave foranea entre ambas.
     * Es el precio y la prueba de la separacion.
     */
    public function test_security_events_are_not_affected_by_survey_deletion(): void
    {
        $survey = Survey::factory()->published()->create();
        SecurityEvent::factory()->create(['survey_id' => $survey->id]);

        $survey->delete();

        $this->assertSame(1, SecurityEvent::where('survey_id', $survey->id)->count());
    }

    /** brief §5.3: retencion configurable. Purga por SecurityEvent::scopeOlderThan. */
    public function test_security_event_retention_scope_selects_only_old_rows(): void
    {
        SecurityEvent::factory()->create();
        SecurityEvent::factory()->olderThanDays(45)->create();

        $cutoff = now()->subDays(SecurityEvent::DEFAULT_RETENTION_DAYS);

        $this->assertSame(1, SecurityEvent::olderThan($cutoff)->count());
        $this->assertSame(2, SecurityEvent::count());
    }

    /** ARCHITECTURE §7.3: occurred_at se trunca al minuto, siempre. */
    public function test_security_event_timestamp_is_truncated_to_the_minute(): void
    {
        $event = SecurityEvent::factory()->create([
            'occurred_at' => now()->setTime(14, 37, 53),
        ]);

        $this->assertSame(0, $event->fresh()->occurred_at->second);
    }
}
