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
use Tests\TestCase;

/**
 * Restricciones de unicidad. ADR-0005 capa 3: "la base de datos arbitra, no la
 * aplicacion". Todas se comprueban provocando la violacion de verdad.
 */
class UniquenessConstraintsTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_id_is_unique(): void
    {
        $publicId = 'K7M2QX9RTB4WVZ3A';
        Survey::factory()->create(['public_id' => $publicId]);

        $this->expectException(QueryException::class);
        Survey::factory()->create(['public_id' => $publicId]);
    }

    /** ADR-0006: los borradores no tienen public_id; varios NULL deben convivir. */
    public function test_multiple_surveys_may_have_null_public_id(): void
    {
        Survey::factory()->count(3)->draft()->create();

        $this->assertSame(3, Survey::whereNull('public_id')->count());
    }

    /** ADR-0004/0005: una fila por token y encuesta -> imposible el doble conteo. */
    public function test_same_token_twice_in_the_same_survey_is_rejected(): void
    {
        $survey = Survey::factory()->published()->create();
        $hash = ParticipantSession::hashToken('token-de-prueba');

        ParticipantSession::factory()->create([
            'survey_id' => $survey->id,
            'token_hash' => $hash,
        ]);

        $this->expectException(QueryException::class);
        ParticipantSession::factory()->create([
            'survey_id' => $survey->id,
            'token_hash' => $hash,
        ]);
    }

    /**
     * ADR-0005: el alcance del token es POR ENCUESTA. El mismo hash en otra
     * encuesta es legitimo (y en la practica imposible, porque Path=/s/{publicId}
     * impide que el navegador reutilice la cookie). La unicidad NO es global.
     */
    public function test_same_token_hash_is_allowed_in_a_different_survey(): void
    {
        $a = Survey::factory()->published()->create();
        $b = Survey::factory()->published()->create();
        $hash = ParticipantSession::hashToken('token-compartido');

        ParticipantSession::factory()->create(['survey_id' => $a->id, 'token_hash' => $hash]);
        ParticipantSession::factory()->create(['survey_id' => $b->id, 'token_hash' => $hash]);

        $this->assertSame(2, ParticipantSession::where('token_hash', $hash)->count());
    }

    /** ADR-0005 capa 3: UNIQUE(participant_session_id). Un envio por sesion. */
    public function test_a_session_cannot_submit_two_responses(): void
    {
        $session = ParticipantSession::factory()->create();
        Response::factory()->forSession($session)->create();

        $this->expectException(QueryException::class);
        Response::factory()->forSession($session)->create();
    }

    /** RN-05 capa 2: la misma clave de idempotencia no crea una segunda fila. */
    public function test_submission_uuid_is_unique_per_survey(): void
    {
        $survey = Survey::factory()->published()->create();
        $uuid = '7f1c9a2e-0000-4000-8000-000000000001';

        Response::factory()->create(['survey_id' => $survey->id, 'submission_uuid' => $uuid]);

        $this->expectException(QueryException::class);
        Response::factory()->create(['survey_id' => $survey->id, 'submission_uuid' => $uuid]);
    }

    /**
     * REQUISITO DURO DE LA FASE 4:
     * "restriccion de unicidad que impida el doble envio de la misma sesion a la
     *  misma pregunta".
     *
     * Se compone de dos UNIQUE encadenadas:
     *   responses(participant_session_id)       -> 1 envio por sesion
     *   response_answers(response_id, question_id) -> 1 respuesta por pregunta
     */
    public function test_a_response_cannot_answer_the_same_question_twice(): void
    {
        $survey = Survey::factory()->published()->create();
        $question = Question::factory()->singleChoice()->create(['survey_id' => $survey->id]);
        $session = ParticipantSession::factory()->create(['survey_id' => $survey->id]);
        $response = Response::factory()->forSession($session)->create();

        ResponseAnswer::factory()->create([
            'response_id' => $response->id,
            'question_id' => $question->id,
        ]);

        $this->expectException(QueryException::class);
        ResponseAnswer::factory()->create([
            'response_id' => $response->id,
            'question_id' => $question->id,
        ]);
    }

    /** API.md: option_ids "sin duplicados", garantizado tambien por el motor. */
    public function test_the_same_option_cannot_be_selected_twice_in_one_answer(): void
    {
        $question = Question::factory()->multipleChoice()->create();
        $option = QuestionOption::factory()->create(['question_id' => $question->id]);
        $answer = ResponseAnswer::factory()->create(['question_id' => $question->id]);

        ResponseSelection::create([
            'response_answer_id' => $answer->id,
            'question_option_id' => $option->id,
        ]);

        $this->expectException(QueryException::class);
        ResponseSelection::create([
            'response_answer_id' => $answer->id,
            'question_option_id' => $option->id,
        ]);
    }

    public function test_question_positions_are_unique_within_a_survey(): void
    {
        $survey = Survey::factory()->create();
        Question::factory()->atPosition(1)->create(['survey_id' => $survey->id]);

        $this->expectException(QueryException::class);
        Question::factory()->atPosition(1)->create(['survey_id' => $survey->id]);
    }
}
