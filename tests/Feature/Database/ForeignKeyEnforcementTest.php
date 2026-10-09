<?php

namespace Tests\Feature\Database;

use App\Models\ParticipantSession;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Response;
use App\Models\ResponseAnswer;
use App\Models\ResponseSelection;
use App\Models\Survey;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * D-04: "sin esto las FK son decorativas y solo fallarian en produccion.
 *        Demuestralo ejecutando una prueba que intente violar una FK."
 *
 * Estas pruebas INSERTAN filas huerfanas de verdad y exigen que el motor las
 * rechace. Si alguien pone DB_FOREIGN_KEYS=false, esta clase se pone en rojo.
 */
class ForeignKeyEnforcementTest extends TestCase
{
    use RefreshDatabase;

    public function test_orphan_question_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        DB::table('questions')->insert([
            'survey_id' => 999999,          // no existe
            'type' => Question::TYPE_SINGLE_CHOICE,
            'prompt' => 'Pregunta huerfana',
            'is_required' => true,
            'position' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_orphan_participant_session_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        DB::table('participant_sessions')->insert([
            'survey_id' => 999999,
            'token_hash' => str_repeat('a', 64),
            'submission_state' => ParticipantSession::STATE_STARTED,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_orphan_response_selection_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        DB::table('response_selections')->insert([
            'response_answer_id' => 999999,
            'question_option_id' => 999999,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_orphan_response_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        DB::table('responses')->insert([
            'survey_id' => 999999,
            'participant_session_id' => null,
            'submission_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'survey_version_number' => 1,
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Prueba de control: con una FK valida la MISMA insercion si pasa. Evita que
     * la prueba anterior se interprete como "todo inserto falla por otra razon".
     */
    public function test_valid_foreign_key_is_accepted(): void
    {
        $survey = Survey::factory()->published()->create();
        $question = Question::factory()->singleChoice()->create(['survey_id' => $survey->id]);
        $option = QuestionOption::factory()->create(['question_id' => $question->id]);
        $session = ParticipantSession::factory()->create(['survey_id' => $survey->id]);
        $response = Response::factory()->forSession($session)->create();
        $answer = ResponseAnswer::factory()->create([
            'response_id' => $response->id,
            'question_id' => $question->id,
        ]);
        $selection = ResponseSelection::create([
            'response_answer_id' => $answer->id,
            'question_option_id' => $option->id,
        ]);

        $this->assertDatabaseHas('response_selections', ['id' => $selection->id]);
    }
}
