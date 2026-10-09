<?php

namespace Tests\Feature\Surveys;

use App\Domain\Surveys\InvalidTransitionException;
use App\Domain\Surveys\PublicIdGenerator;
use App\Domain\Surveys\SurveyService;
use App\Models\Question;
use App\Models\Response;
use App\Models\Survey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsSurveys;
use Tests\TestCase;

/**
 * Maquina de estados aplicada EN SERVIDOR (PRD RN-01/RN-02, API.md §4.1).
 *
 * Estas pruebas llaman al SERVICIO directamente, sin pasar por la interfaz.
 * Es deliberado: demuestran que la regla vive en el servidor y no en el
 * marcado, que es justo lo que el encargo exige. Una peticion con `curl` que
 * se saltase la UI chocaria contra exactamente este codigo.
 */
class SurveyLifecycleTest extends TestCase
{
    use RefreshDatabase, BuildsSurveys;

    private function service(): SurveyService
    {
        return app(SurveyService::class);
    }

    // ------------------------------------------------- transiciones validas

    public function test_a_valid_draft_can_be_published_and_gets_a_public_id(): void
    {
        $owner = $this->owner();
        $survey = Survey::factory()->draft()->create(['created_by_user_id' => $owner->id]);

        $question = Question::factory()->create([
            'survey_id' => $survey->id,
            'type' => Question::TYPE_SINGLE_CHOICE,
            'position' => 1,
        ]);
        $this->addOptions($question, ['Si', 'No']);

        $published = $this->service()->publish($owner, $survey);

        $this->assertSame(Survey::STATUS_ACTIVE, $published->status);
        $this->assertNotNull($published->public_id);

        // ADR-0006: 16 caracteres Base32 Crockford, sin I, L, O ni U.
        $this->assertTrue(
            PublicIdGenerator::isWellFormed($published->public_id),
            'El public_id debe respetar el alfabeto Crockford de ADR-0006.',
        );
    }

    public function test_publishing_is_idempotent_and_never_regenerates_the_public_id(): void
    {
        $owner = $this->owner();
        $built = $this->publishedSurveyWithAllTypes($owner);
        $original = $built['survey']->public_id;

        $again = $this->service()->publish($owner, $built['survey']);

        // Regenerarlo invalidaria en silencio cualquier QR ya impreso y
        // repartido por una sala (ARCHITECTURE.md §3.1).
        $this->assertSame($original, $again->public_id);
        $this->assertSame(Survey::STATUS_ACTIVE, $again->status);
    }

    public function test_the_happy_path_of_the_state_machine(): void
    {
        $owner = $this->owner();
        $built = $this->publishedSurveyWithAllTypes($owner);
        $survey = $built['survey'];
        $service = $this->service();

        $this->assertSame(Survey::STATUS_PAUSED, $service->pause($owner, $survey)->status);
        $this->assertSame(Survey::STATUS_ACTIVE, $service->resume($owner, $survey)->status);
        $this->assertSame(Survey::STATUS_CLOSED, $service->close($owner, $survey)->status);
        $this->assertSame(Survey::STATUS_ARCHIVED, $service->archive($owner, $survey)->status);
        $this->assertSame(Survey::STATUS_CLOSED, $service->unarchive($owner, $survey)->status);
    }

    // ----------------------------------------------- transiciones INVALIDAS

    /**
     * El cierre es IRREVERSIBLE (D-05 PA-04). No hay arista de vuelta.
     *
     * PHPUnit 12 ya no lee la anotacion @dataProvider: exige el atributo.
     */
    #[DataProvider('forbiddenTransitions')]
    public function test_invalid_transitions_are_rejected_by_the_server(
        string $factoryState,
        string $action,
    ): void {
        $owner = $this->owner();
        $survey = Survey::factory()->{$factoryState}()->create([
            'created_by_user_id' => $owner->id,
        ]);

        $this->expectException(InvalidTransitionException::class);

        $this->service()->{$action}($owner, $survey);
    }

    public static function forbiddenTransitions(): array
    {
        return [
            'una cerrada no se reanuda' => ['closed', 'resume'],
            'una cerrada no se pausa' => ['closed', 'pause'],
            'una cerrada no se republica' => ['closed', 'publish'],
            'una cerrada no se cierra dos veces' => ['closed', 'close'],
            'un borrador no se cierra' => ['draft', 'close'],
            'un borrador no se pausa' => ['draft', 'pause'],
            'un borrador no se archiva' => ['draft', 'archive'],
            'una activa no se reanuda' => ['published', 'resume'],
            'una activa no se archiva sin cerrar' => ['published', 'archive'],
            'una pausada no se pausa dos veces' => ['paused', 'pause'],
            'una archivada no se reanuda' => ['archived', 'resume'],
            'una archivada no se publica' => ['archived', 'publish'],
        ];
    }

    public function test_the_rejection_explains_the_alternative_instead_of_a_code(): void
    {
        $owner = $this->owner();
        $survey = Survey::factory()->closed()->create(['created_by_user_id' => $owner->id]);

        try {
            $this->service()->resume($owner, $survey);
            $this->fail('Se esperaba InvalidTransitionException.');
        } catch (InvalidTransitionException $e) {
            $this->assertStringContainsString('cerrada', $e->getMessage());
            $this->assertStringContainsString('Duplicala', $e->getMessage());
            // Se traduce a 409, no a 500 ni a 422 (API.md §0.6).
            $this->assertSame(409, $e->status());
        }
    }

    // -------------------------------------------------- validacion RN-01

    public function test_a_survey_without_questions_cannot_be_published(): void
    {
        $owner = $this->owner();
        $survey = Survey::factory()->draft()->create(['created_by_user_id' => $owner->id]);

        try {
            $this->service()->publish($owner, $survey);
            $this->fail('Se esperaba ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('questions', $e->errors());
        }

        // RN-01: el rechazo es ATOMICO. No queda a medias.
        $this->assertSame(Survey::STATUS_DRAFT, $survey->fresh()->status);
        $this->assertNull($survey->fresh()->public_id);
    }

    public function test_a_choice_question_with_one_option_blocks_publication(): void
    {
        $owner = $this->owner();
        $survey = Survey::factory()->draft()->create(['created_by_user_id' => $owner->id]);

        $question = Question::factory()->create([
            'survey_id' => $survey->id,
            'type' => Question::TYPE_SINGLE_CHOICE,
            'position' => 1,
        ]);
        $this->addOptions($question, ['Unica']);

        $this->expectException(ValidationException::class);
        $this->service()->publish($owner, $survey);
    }

    /**
     * RN-01: la publicacion devuelve la lista COMPLETA de problemas, no el
     * primero. Obligar a alguien a publicar nueve veces para descubrir nueve
     * errores de uno en uno es un mal producto.
     */
    public function test_publication_reports_every_blocker_at_once(): void
    {
        $owner = $this->owner();
        $survey = Survey::factory()->draft()->create([
            'created_by_user_id' => $owner->id,
            'title' => 'ab', // demasiado corto
        ]);

        $q1 = Question::factory()->create([
            'survey_id' => $survey->id,
            'type' => Question::TYPE_SINGLE_CHOICE,
            'position' => 1,
        ]);
        $this->addOptions($q1, ['Solo una']);

        $q2 = Question::factory()->create([
            'survey_id' => $survey->id,
            'type' => Question::TYPE_MULTIPLE_CHOICE,
            'position' => 2,
            'min_selections' => 5,
            'max_selections' => 2, // min > max
        ]);
        $this->addOptions($q2, ['A', 'B']);

        try {
            $this->service()->publish($owner, $survey);
            $this->fail('Se esperaba ValidationException.');
        } catch (ValidationException $e) {
            $errors = $e->errors();

            $this->assertArrayHasKey('title', $errors);
            $this->assertArrayHasKey('question.' . $q1->id, $errors);
            $this->assertArrayHasKey('question.' . $q2->id, $errors);
            $this->assertGreaterThanOrEqual(3, count($errors));
        }
    }

    public function test_duplicate_options_within_a_question_block_publication(): void
    {
        $owner = $this->owner();
        $survey = Survey::factory()->draft()->create(['created_by_user_id' => $owner->id]);

        $question = Question::factory()->create([
            'survey_id' => $survey->id,
            'type' => Question::TYPE_SINGLE_CHOICE,
            'position' => 1,
        ]);
        // "Si" y "si" son indistinguibles para quien responde.
        $this->addOptions($question, ['Si', 'si']);

        $this->expectException(ValidationException::class);
        $this->service()->publish($owner, $survey);
    }

    // ----------------------------------------------------------- duplicar

    public function test_duplicating_creates_a_new_draft_without_public_id_or_responses(): void
    {
        $owner = $this->owner();
        $built = $this->publishedSurveyWithAllTypes($owner);
        $survey = $built['survey'];

        Response::factory()->create([
            'survey_id' => $survey->id,
            'participant_session_id' => null,
        ]);

        $copy = $this->service()->duplicate($owner, $survey);

        $this->assertSame(Survey::STATUS_DRAFT, $copy->status);
        $this->assertSame($survey->version_number + 1, $copy->version_number);
        $this->assertSame($survey->id, $copy->parent_survey_id);

        // Sin public_id: el QR antiguo debe seguir apuntando a la original.
        $this->assertNull($copy->public_id);

        // Estructura copiada, respuestas NO.
        $this->assertSame(3, $copy->questions()->count());
        $this->assertSame(0, $copy->responses()->count());
        $this->assertSame(
            $survey->questions()->first()->options()->count(),
            $copy->questions()->first()->options()->count(),
        );
    }

    public function test_a_draft_with_responses_cannot_be_deleted(): void
    {
        $owner = $this->owner();
        $survey = Survey::factory()->draft()->create(['created_by_user_id' => $owner->id]);

        Response::factory()->create([
            'survey_id' => $survey->id,
            'participant_session_id' => null,
        ]);

        $this->expectException(InvalidTransitionException::class);
        $this->service()->delete($owner, $survey);
    }

    // ----------------------------------------------- estructura congelada

    public function test_the_structure_is_frozen_once_published(): void
    {
        $owner = $this->owner();
        $built = $this->publishedSurveyWithAllTypes($owner);

        $questions = app(\App\Domain\Surveys\QuestionService::class);

        $this->expectException(InvalidTransitionException::class);

        $questions->addQuestion($built['survey'], [
            'type' => Question::TYPE_SINGLE_CHOICE,
            'prompt' => 'Una pregunta colada despues de publicar',
        ]);
    }

    public function test_closing_writes_the_snapshot_so_history_survives_the_purge(): void
    {
        $owner = $this->owner();
        $built = $this->publishedSurveyWithAllTypes($owner);
        $survey = $built['survey'];

        $closed = $this->service()->close($owner, $survey);

        // RN-02.5: sin esta instantanea, la tasa de una encuesta antigua
        // pasaria a indefinida el dia que la purga borre las sesiones.
        $this->assertNotNull($closed->snapshot_sessions_started);
        $this->assertNotNull($closed->snapshot_completed);
        $this->assertNotNull($closed->snapshot_response_rate);
        $this->assertNotNull($closed->closed_at);
        $this->assertSame($owner->id, $closed->closed_by_user_id);
    }
}
