<?php

namespace Tests\Feature\Participation;

use App\Models\ParticipantSession;
use App\Models\Question;
use App\Models\Response;
use App\Models\ResponseAnswer;
use App\Models\Survey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Support\BuildsSurveys;
use Tests\TestCase;

/**
 * Participacion anonima de extremo a extremo (CA-03..CA-07, CA-13).
 *
 * Todas estas pruebas van por HTTP, no por el servicio: lo que se verifica es
 * el comportamiento que vera un participante real, incluidos middleware,
 * cookies, CSRF y codigos de estado.
 */
class ParticipationTest extends TestCase
{
    use RefreshDatabase, BuildsSurveys;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('response-submit');
    }

    private function url(Survey $survey, string $suffix = ''): string
    {
        return '/s/' . $survey->public_id . $suffix;
    }

    // ------------------------------------------------------- CA-03 acceso

    public function test_anyone_can_open_a_published_survey_without_an_account(): void
    {
        $built = $this->publishedSurveyWithAllTypes();

        $response = $this->get($this->url($built['survey']));

        $response->assertOk();
        $response->assertSee('Evaluacion de la jornada');
        $response->assertSee('Como valoras la jornada?');

        // Se emite una sesion anonima, sin pedir ningun dato personal.
        $this->assertSame(1, ParticipantSession::query()->count());

        $session = ParticipantSession::query()->first();
        $this->assertSame($built['survey']->id, $session->survey_id);
        $this->assertSame(64, strlen($session->token_hash)); // SHA-256 hex

        // ADR-0005: en BD vive el HASH, jamas el token en claro.
        $this->assertArrayNotHasKey('token', $session->getAttributes());
    }

    public function test_reloading_the_page_resumes_the_same_session_and_does_not_inflate_metrics(): void
    {
        $built = $this->publishedSurveyWithAllTypes();

        $this->openSurvey($built['survey'])->assertOk();
        $this->openSurvey($built['survey'])->assertOk();
        $this->openSurvey($built['survey'])->assertOk();

        // Tres cargas, UNA sesion: `sesiones_iniciadas` no se infla y la tasa
        // de respuesta de D-07 sigue significando lo que dice.
        $this->assertSame(1, ParticipantSession::query()->count());
    }

    /**
     * ADR-0005: el alcance del token es POR ENCUESTA. Dos encuestas producen
     * dos sesiones no relacionables, en el mismo dispositivo y el mismo dia.
     */
    public function test_sessions_are_scoped_per_survey_and_cannot_be_correlated(): void
    {
        $a = $this->publishedSurveyWithAllTypes()['survey'];
        $b = $this->publishedSurveyWithAllTypes()['survey'];

        $this->get($this->url($a))->assertOk();
        $this->get($this->url($b))->assertOk();

        $sessions = ParticipantSession::query()->get();

        $this->assertCount(2, $sessions);
        $this->assertNotSame($sessions[0]->token_hash, $sessions[1]->token_hash);
        $this->assertNotSame($sessions[0]->survey_id, $sessions[1]->survey_id);
    }

    // -------------------------------------------------- CA-04/05 validacion

    public function test_a_valid_submission_is_recorded(): void
    {
        $built = $this->publishedSurveyWithAllTypes();

        $this->openSurvey($built['survey']);

        $response = $this->submitAs($built['survey'], $this->validAnswers($built));

        $response->assertOk();
        $response->assertJsonPath('status', 'recorded');
        $response->assertJsonPath('duplicate', false);
        $this->assertNotEmpty($response->json('receipt_id'));

        $this->assertSame(1, Response::query()->count());
        $this->assertSame(3, ResponseAnswer::query()->count());

        // UNA sola sesion en toda la visita, y queda como completada.
        $this->assertSame(1, ParticipantSession::query()->count());
        $this->assertSame(
            ParticipantSession::STATE_COMPLETED,
            ParticipantSession::query()->first()->submission_state,
        );
    }

    /**
     * CA-05: los limites de seleccion multiple se validan EN SERVIDOR contra
     * el esquema real leido de la base de datos, no contra lo que diga el
     * cliente.
     */
    public function test_multiple_choice_below_the_minimum_is_rejected(): void
    {
        $built = $this->publishedSurveyWithAllTypes();
        $this->get($this->url($built['survey']));

        $answers = $this->validAnswers($built);
        // min_selections = 2; se envia solo 1.
        $answers[1]['option_ids'] = [$built['multiple']->options()->first()->id];

        $this->postJson($this->url($built['survey'], '/responses'), [
            'submission_uuid' => (string) Str::uuid(),
            'answers' => $answers,
        ])->assertStatus(422)->assertJsonValidationErrors(['answers.1.option_ids']);

        $this->assertSame(0, Response::query()->count());
    }

    public function test_multiple_choice_above_the_maximum_is_rejected(): void
    {
        $built = $this->publishedSurveyWithAllTypes();
        $this->get($this->url($built['survey']));

        $answers = $this->validAnswers($built);
        // max_selections = 3; se envian las 4 opciones.
        $answers[1]['option_ids'] = $built['multiple']->options()->pluck('id')->all();

        $this->postJson($this->url($built['survey'], '/responses'), [
            'submission_uuid' => (string) Str::uuid(),
            'answers' => $answers,
        ])->assertStatus(422)->assertJsonValidationErrors(['answers.1.option_ids']);

        $this->assertSame(0, Response::query()->count());
    }

    public function test_an_option_belonging_to_another_survey_is_a_422_not_a_500(): void
    {
        $built = $this->publishedSurveyWithAllTypes();
        $other = $this->publishedSurveyWithAllTypes();

        $this->get($this->url($built['survey']));

        $answers = $this->validAnswers($built);
        $answers[0]['option_ids'] = [$other['single']->options()->first()->id];

        $this->postJson($this->url($built['survey'], '/responses'), [
            'submission_uuid' => (string) Str::uuid(),
            'answers' => $answers,
        ])->assertStatus(422);

        $this->assertSame(0, Response::query()->count());
    }

    public function test_a_question_from_another_survey_is_rejected_not_silently_ignored(): void
    {
        $built = $this->publishedSurveyWithAllTypes();
        $other = $this->publishedSurveyWithAllTypes();

        $this->get($this->url($built['survey']));

        $answers = $this->validAnswers($built);
        $answers[] = ['question_id' => $other['single']->id, 'option_ids' => []];

        $this->postJson($this->url($built['survey'], '/responses'), [
            'submission_uuid' => (string) Str::uuid(),
            'answers' => $answers,
        ])->assertStatus(422);
    }

    public function test_a_missing_required_question_is_rejected(): void
    {
        $built = $this->publishedSurveyWithAllTypes();
        $this->get($this->url($built['survey']));

        $answers = $this->validAnswers($built);
        unset($answers[0]); // falta la obligatoria de seleccion unica

        $this->postJson($this->url($built['survey'], '/responses'), [
            'submission_uuid' => (string) Str::uuid(),
            'answers' => array_values($answers),
        ])->assertStatus(422);

        $this->assertSame(0, Response::query()->count());
    }

    /**
     * DATABASE.md §10.1: SQLite NO aplica longitudes de VARCHAR. Si esta
     * validacion en PHP no existiera, el texto entraria aqui sin protestar y
     * reventaria en MySQL 8, es decir, en produccion.
     */
    public function test_free_text_longer_than_its_limit_is_rejected_in_php(): void
    {
        $built = $this->publishedSurveyWithAllTypes();
        $this->get($this->url($built['survey']));

        $answers = $this->validAnswers($built, str_repeat('a', 201)); // max_length = 200

        $this->postJson($this->url($built['survey'], '/responses'), [
            'submission_uuid' => (string) Str::uuid(),
            'answers' => $answers,
        ])->assertStatus(422)->assertJsonValidationErrors(['answers.2.text']);

        $this->assertSame(0, Response::query()->count());
    }

    // ------------------------------------------------------ CA-06 XSS

    /**
     * CA-06: el texto malicioso se almacena EN CRUDO y se escapa al
     * renderizar. Sanear en la entrada destruiria el dato original y daria
     * falsa seguridad.
     */
    public function test_malicious_free_text_is_stored_raw_and_rendered_escaped(): void
    {
        $payload = '<script>alert("xss")</script>';

        $built = $this->publishedSurveyWithAllTypes();
        $this->get($this->url($built['survey']));

        $this->postJson($this->url($built['survey'], '/responses'), [
            'submission_uuid' => (string) Str::uuid(),
            'answers' => $this->validAnswers($built, $payload),
        ])->assertOk();

        // 1. En la base de datos, EXACTAMENTE lo que la persona escribio.
        $stored = ResponseAnswer::query()
            ->where('question_id', $built['text']->id)
            ->value('text_answer');

        $this->assertSame($payload, $stored, 'El texto debe guardarse en crudo.');

        // 2. En el dashboard, escapado: la etiqueta no se ejecuta.
        $owner = $built['survey']->creator;

        $html = $this->actingAs($owner)
            ->get(route('admin.surveys.results', $built['survey']))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(
            '<script>alert("xss")</script>',
            $html,
            'El texto del participante NUNCA debe salir sin escapar.',
        );
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    // --------------------------------------------- CA-07 anti-duplicado

    /**
     * Capa 2 de ADR-0005: idempotencia. Un reintento tras un corte de red
     * devuelve el recibo original con 200, NO un 409.
     *
     * Es el caso del participante cuya red fallo DESPUES de que el servidor
     * guardase: hizo todo bien y no debe ver un error.
     */
    public function test_resubmitting_the_same_uuid_returns_the_original_receipt(): void
    {
        $built = $this->publishedSurveyWithAllTypes();
        $url = $this->url($built['survey'], '/responses');

        $this->get($this->url($built['survey']));

        $uuid = (string) Str::uuid();
        $body = ['submission_uuid' => $uuid, 'answers' => $this->validAnswers($built)];

        $first = $this->postJson($url, $body)->assertOk();
        $second = $this->postJson($url, $body)->assertOk();

        $this->assertFalse($first->json('duplicate'));
        $this->assertTrue($second->json('duplicate'));
        $this->assertSame($first->json('receipt_id'), $second->json('receipt_id'));

        // UNA sola fila. La defensa ultima es el UNIQUE de la base de datos.
        $this->assertSame(1, Response::query()->count());
        $this->assertSame(3, ResponseAnswer::query()->count());
    }

    public function test_the_same_session_cannot_submit_twice_even_with_a_different_uuid(): void
    {
        $built = $this->publishedSurveyWithAllTypes();

        $this->openSurvey($built['survey']);

        $this->submitAs($built['survey'], $this->validAnswers($built))->assertOk();

        // Otro uuid, misma sesion: choca con UNIQUE(participant_session_id).
        $second = $this->submitAs(
            $built['survey'],
            $this->validAnswers($built, 'Intento de cambiar la respuesta'),
        )->assertOk();

        $this->assertTrue($second->json('duplicate'));
        $this->assertSame(1, Response::query()->count());

        // RN-04: la respuesta original NO se modifica.
        $this->assertSame(
            'Todo bien.',
            ResponseAnswer::query()->where('question_id', $built['text']->id)->value('text_answer'),
        );
    }

    public function test_an_already_answered_session_sees_the_confirmation_not_the_form(): void
    {
        $built = $this->publishedSurveyWithAllTypes();

        $this->openSurvey($built['survey']);
        $this->submitAs($built['survey'], $this->validAnswers($built))->assertOk();

        // Volver a abrir NO debe mostrar el formulario: invitaria a
        // rellenarlo para despues rechazar el envio (RN-04).
        $page = $this->openSurvey($built['survey'])->assertOk();

        $page->assertSee('Respuesta enviada');
        $page->assertDontSee('Enviar respuestas');
    }

    // ------------------------------------------------ CA-13 encuesta cerrada

    /**
     * CA-13: el rechazo ocurre EN SERVIDOR, no ocultando el boton. La
     * peticion llega como llegaria con `curl`.
     */
    public function test_a_closed_survey_rejects_submissions_with_410(): void
    {
        $owner = $this->owner();
        $built = $this->publishedSurveyWithAllTypes($owner);
        $survey = $built['survey'];

        $this->get($this->url($survey));

        $survey->forceFill([
            'status' => Survey::STATUS_CLOSED,
            'closed_at' => now(),
        ])->save();

        $this->postJson($this->url($survey, '/responses'), [
            'submission_uuid' => (string) Str::uuid(),
            'answers' => $this->validAnswers($built),
        ])->assertStatus(410);

        $this->assertSame(0, Response::query()->count());
    }

    public function test_a_paused_survey_rejects_submissions_with_410(): void
    {
        $built = $this->publishedSurveyWithAllTypes();
        $survey = $built['survey'];

        $this->get($this->url($survey));
        $survey->forceFill(['status' => Survey::STATUS_PAUSED])->save();

        $this->postJson($this->url($survey, '/responses'), [
            'submission_uuid' => (string) Str::uuid(),
            'answers' => $this->validAnswers($built),
        ])->assertStatus(410);

        $this->assertSame(0, Response::query()->count());
    }

    public function test_a_survey_past_its_end_date_rejects_submissions(): void
    {
        $built = $this->publishedSurveyWithAllTypes();
        $survey = $built['survey'];

        $this->get($this->url($survey));

        $survey->forceFill(['ends_at' => now()->subMinute()])->save();

        // RN-11: la ventana vencida cierra la encuesta de forma perezosa.
        $this->postJson($this->url($survey, '/responses'), [
            'submission_uuid' => (string) Str::uuid(),
            'answers' => $this->validAnswers($built),
        ])->assertStatus(410);

        $this->assertSame(0, Response::query()->count());
    }

    public function test_the_closed_survey_page_explains_itself_instead_of_404(): void
    {
        $survey = Survey::factory()->closed()->create();

        // Una encuesta cerrada SI resuelve: quien escaneo un QR impreso
        // merece una explicacion, no un 404 desconcertante.
        $this->get($this->url($survey))
            ->assertOk()
            ->assertSee('Encuesta cerrada');
    }

    // --------------------------------------------- enumeracion y 404

    /**
     * Borrador, archivada e inexistente devuelven EXACTAMENTE lo mismo.
     * Distinguirlos permitiria enumerar identificadores validos y averiguar
     * que encuestas existen en borrador (brief §6).
     */
    public function test_draft_archived_and_unknown_are_indistinguishable_404s(): void
    {
        $draft = Survey::factory()->draft()->create();
        $archived = Survey::factory()->archived()->create();

        $this->assertNull($draft->public_id);

        $this->get('/s/' . $archived->public_id)->assertNotFound();
        $this->get('/s/ZZZZZZZZZZZZZZZZ')->assertNotFound();

        // Identificador con caracteres fuera del alfabeto Crockford (I, L,
        // O, U): ni siquiera llega a consultar la base de datos.
        $this->get('/s/IIIILLLLOOOOUUUU')->assertNotFound();
    }

    // -------------------------------------------------------- heartbeat

    public function test_the_heartbeat_renews_presence(): void
    {
        $built = $this->publishedSurveyWithAllTypes();

        $this->openSurvey($built['survey']);

        $session = ParticipantSession::query()->first();
        $session->forceFill(['last_seen_at' => now()->subMinutes(5)])->save();

        $this->assertFalse($session->fresh()->isConnected());

        $this->asParticipant()
            ->post($this->url($built['survey'], '/heartbeat'))
            ->assertNoContent();

        // Y sigue siendo LA MISMA sesion: el heartbeat no crea ninguna.
        $this->assertSame(1, ParticipantSession::query()->count());
        $this->assertTrue($session->fresh()->isConnected());
    }

    public function test_the_heartbeat_of_a_closed_survey_returns_410_so_the_client_stops(): void
    {
        $built = $this->publishedSurveyWithAllTypes();
        $survey = $built['survey'];

        $this->get($this->url($survey));
        $survey->forceFill(['status' => Survey::STATUS_CLOSED])->save();

        // El cliente DEBE detener los heartbeats al recibir 410 (API.md §2.3).
        $this->postJson($this->url($survey, '/heartbeat'))->assertStatus(410);
    }
}
