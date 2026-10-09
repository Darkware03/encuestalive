<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Admin\LiveDashboard;
use App\Livewire\Admin\SurveyEditor;
use App\Livewire\Admin\SurveyList;
use App\Models\Question;
use App\Models\Survey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\BuildsSurveys;
use Tests\TestCase;

/**
 * Componentes Livewire con `Livewire::test()`.
 *
 * El punto de estas pruebas es que las acciones se invocan DIRECTAMENTE, sin
 * pasar por el marcado. Es justo el ataque que importa: que el boton no se
 * pinte no impide que alguien llame al metodo. Si la autorizacion viviera en
 * la vista, estas pruebas lo destaparian.
 */
class AdminComponentsTest extends TestCase
{
    use RefreshDatabase, BuildsSurveys;

    // --------------------------------------------------------- SurveyList

    public function test_the_list_only_shows_surveys_of_the_logged_in_owner(): void
    {
        $owner = $this->owner();
        $other = User::factory()->create();

        Survey::factory()->published()->create([
            'created_by_user_id' => $owner->id,
            'title' => 'Mi encuesta propia',
        ]);
        Survey::factory()->published()->create([
            'created_by_user_id' => $other->id,
            'title' => 'Encuesta de otra persona',
        ]);

        Livewire::actingAs($owner)
            ->test(SurveyList::class)
            ->assertSee('Mi encuesta propia')
            ->assertDontSee('Encuesta de otra persona');
    }

    public function test_creating_a_survey_from_the_panel_persists_a_draft(): void
    {
        $owner = $this->owner();

        Livewire::actingAs($owner)
            ->test(SurveyList::class)
            ->call('createSurvey');

        $this->assertDatabaseHas('surveys', [
            'created_by_user_id' => $owner->id,
            'status' => Survey::STATUS_DRAFT,
        ]);
    }

    /**
     * Llamada directa al metodo sobre una encuesta AJENA. La UI nunca pintaria
     * ese boton; el servidor debe rechazarlo igual.
     */
    public function test_an_owner_cannot_close_another_owners_survey_via_a_direct_call(): void
    {
        $owner = $this->owner();
        $victim = Survey::factory()->published()->create();

        Livewire::actingAs($owner)
            ->test(SurveyList::class)
            ->call('close', $victim->id)
            ->assertStatus(404);

        // 404 y no 403: no se confirma que ese id exista.
        $this->assertSame(Survey::STATUS_ACTIVE, $victim->fresh()->status);
    }

    /**
     * Reanudar una encuesta CERRADA propia: 403, no 500 y no un cambio de
     * estado silencioso.
     *
     * Es 403 y no un mensaje en linea porque ARCHITECTURE.md §5.2 define la
     * habilidad `resume` como "propietario + transicion valida en la maquina
     * de estados": el propio permiso incorpora el estado, asi que la policy
     * deniega antes de llegar al servicio.
     *
     * Es la respuesta correcta para una llamada directa, que es la unica
     * forma de llegar aqui: la pantalla no pinta ese boton. Al usuario normal
     * nunca le aparece un 403, porque nunca se le ofrece la accion.
     */
    public function test_resuming_a_closed_survey_is_forbidden_even_for_its_owner(): void
    {
        $owner = $this->owner();
        $closed = Survey::factory()->closed()->create(['created_by_user_id' => $owner->id]);

        Livewire::actingAs($owner)
            ->test(SurveyList::class)
            ->call('resume', $closed->id)
            ->assertForbidden();

        // El cierre es irreversible: el estado no se mueve (D-05 PA-04).
        $this->assertSame(Survey::STATUS_CLOSED, $closed->fresh()->status);
    }

    // ------------------------------------------------------- SurveyEditor

    public function test_the_editor_rejects_a_foreign_survey_on_mount(): void
    {
        $intruder = User::factory()->create();
        $survey = Survey::factory()->published()->create();

        Livewire::actingAs($intruder)
            ->test(SurveyEditor::class, ['survey' => $survey])
            ->assertForbidden();
    }

    public function test_questions_can_be_added_and_configured_in_a_draft(): void
    {
        $owner = $this->owner();
        $draft = Survey::factory()->draft()->create(['created_by_user_id' => $owner->id]);

        $component = Livewire::actingAs($owner)
            ->test(SurveyEditor::class, ['survey' => $draft])
            ->call('addQuestion', Question::TYPE_MULTIPLE_CHOICE);

        $question = $draft->fresh()->questions()->first();

        $this->assertNotNull($question);
        $this->assertSame(Question::TYPE_MULTIPLE_CHOICE, $question->type);
        // Nace con dos opciones: el minimo que RN-01.4 exige.
        $this->assertSame(2, $question->options()->count());

        $component->call('addOption', $question->id);
        $this->assertSame(3, $question->fresh()->options()->count());

        $component->call('setSelectionLimits', $question->id, 2, 3);
        $this->assertSame(2, $question->fresh()->min_selections);
        $this->assertSame(3, $question->fresh()->max_selections);
    }

    /** RN-01.5 aplicado tambien desde el componente, no solo en el servicio. */
    public function test_invalid_selection_limits_are_rejected_by_the_editor(): void
    {
        $owner = $this->owner();
        $draft = Survey::factory()->draft()->create(['created_by_user_id' => $owner->id]);

        $component = Livewire::actingAs($owner)
            ->test(SurveyEditor::class, ['survey' => $draft])
            ->call('addQuestion', Question::TYPE_MULTIPLE_CHOICE);

        $question = $draft->fresh()->questions()->first();

        // min > max
        $component->call('setSelectionLimits', $question->id, 5, 2)
            ->assertHasErrors('min_selections');
    }

    /**
     * ESTRUCTURA CONGELADA: con la encuesta publicada, una llamada directa a
     * `addQuestion` debe fracasar aunque el boton no exista en la pantalla.
     */
    public function test_the_editor_refuses_structural_changes_once_published(): void
    {
        $owner = $this->owner();
        $built = $this->publishedSurveyWithAllTypes($owner);

        Livewire::actingAs($owner)
            ->test(SurveyEditor::class, ['survey' => $built['survey']])
            ->call('addQuestion', Question::TYPE_SINGLE_CHOICE)
            ->assertForbidden();

        $this->assertSame(3, $built['survey']->fresh()->questions()->count());
    }

    public function test_publishing_from_the_editor_lists_every_blocker(): void
    {
        $owner = $this->owner();
        $draft = Survey::factory()->draft()->create([
            'created_by_user_id' => $owner->id,
            'title' => 'ab',
        ]);

        Livewire::actingAs($owner)
            ->test(SurveyEditor::class, ['survey' => $draft])
            ->call('publish')
            ->assertHasErrors(['title', 'questions']);

        $this->assertSame(Survey::STATUS_DRAFT, $draft->fresh()->status);
        $this->assertNull($draft->fresh()->public_id);
    }

    public function test_publishing_a_valid_draft_shows_the_qr(): void
    {
        $owner = $this->owner();
        $draft = Survey::factory()->draft()->create(['created_by_user_id' => $owner->id]);

        $question = Question::factory()->create([
            'survey_id' => $draft->id,
            'type' => Question::TYPE_SINGLE_CHOICE,
            'prompt' => 'Te gusto?',
            'position' => 1,
        ]);
        $this->addOptions($question, ['Si', 'No']);

        Livewire::actingAs($owner)
            ->test(SurveyEditor::class, ['survey' => $draft])
            ->call('publish')
            ->assertHasNoErrors()
            ->assertSet('showQr', true)
            ->assertSee('Descargar PNG')
            ->assertSee('Descargar SVG');

        $fresh = $draft->fresh();
        $this->assertSame(Survey::STATUS_ACTIVE, $fresh->status);
        $this->assertNotNull($fresh->public_id);
    }

    // ------------------------------------------------------ LiveDashboard

    public function test_the_dashboard_rejects_a_foreign_survey(): void
    {
        $intruder = User::factory()->create();
        $survey = Survey::factory()->published()->create();

        Livewire::actingAs($intruder)
            ->test(LiveDashboard::class, ['survey' => $survey])
            ->assertForbidden();
    }

    /**
     * D-02: el dashboard arranca en `degraded` y por tanto SONDEA.
     *
     * Asumir que hay WebSocket hasta que se demuestre lo contrario dejaria la
     * pantalla congelada si Reverb no estuviera levantado. El respaldo es el
     * estado por defecto, no el plan B.
     */
    public function test_the_dashboard_polls_by_default_and_declares_it(): void
    {
        $owner = $this->owner();
        $built = $this->publishedSurveyWithAllTypes($owner);

        $component = Livewire::actingAs($owner)
            ->test(LiveDashboard::class, ['survey' => $built['survey']]);

        $component->assertSet('connectionState', 'degraded');

        // El respaldo obligatorio de 5 s esta en el marcado...
        $component->assertSeeHtml('wire:poll.5s');
        // ...y se DECLARA en pantalla, sin fingir tiempo real.
        $component->assertSee('tiempo real no disponible');
    }

    /** Conecta el `setConnectionState()` que el indicador ya exponia. */
    public function test_the_connection_state_drives_the_polling_interval(): void
    {
        $owner = $this->owner();
        $built = $this->publishedSurveyWithAllTypes($owner);

        $component = Livewire::actingAs($owner)
            ->test(LiveDashboard::class, ['survey' => $built['survey']]);

        // Conectado: manda el WebSocket, el sondeo se APAGA.
        $component->call('setConnectionState', 'connected')
            ->assertSet('connectionState', 'connected')
            ->assertDontSeeHtml('wire:poll');

        // Reconectando: 10 s, para que no se congele durante el corte.
        $component->call('setConnectionState', 'reconnecting')
            ->assertSeeHtml('wire:poll.10s');

        // Degradado: 5 s permanente.
        $component->call('setConnectionState', 'degraded')
            ->assertSeeHtml('wire:poll.5s');

        // Un estado inventado se ignora en lugar de romper la pantalla.
        $component->call('setConnectionState', 'inventado')
            ->assertSet('connectionState', 'degraded');
    }

    /**
     * LA REGLA DURA DE D-02: el evento de Echo es una SENAL, no un dato.
     *
     * Se envia una carga util con cifras falsas y se comprueba que el
     * dashboard pinta las de la BASE DE DATOS, no las del payload.
     */
    public function test_the_dashboard_ignores_the_event_payload_and_rereads_the_database(): void
    {
        $owner = $this->owner();
        $built = $this->publishedSurveyWithAllTypes($owner);

        $this->openSurvey($built['survey']);
        $this->submitAs($built['survey'], $this->validAnswers($built))->assertOk();

        $component = Livewire::actingAs($owner)
            ->test(LiveDashboard::class, ['survey' => $built['survey']]);

        // 1 respuesta real en la base de datos.
        $component->assertSee('Respuestas completadas');

        $component->call('onStatsUpdated', [
            'stats' => [
                'completed_responses' => 9999,
                'connected_sessions' => 8888,
            ],
        ]);

        // Las cifras del payload NO aparecen: se descartaron.
        $component->assertDontSee('9999');
        $component->assertDontSee('8888');
    }

    /** D-06: el denominador se declara SIEMPRE en pantalla, no en un tooltip. */
    public function test_the_dashboard_declares_the_percentage_denominator(): void
    {
        $owner = $this->owner();
        $built = $this->publishedSurveyWithAllTypes($owner);

        $this->openSurvey($built['survey']);
        $this->submitAs($built['survey'], $this->validAnswers($built))->assertOk();

        Livewire::actingAs($owner)
            ->test(LiveDashboard::class, ['survey' => $built['survey']])
            ->assertSee('participantes que respondieron esta pregunta')
            ->assertSee('superar el 100');
    }

    /** brief §4.5: poder ocultar el texto libre con la pantalla proyectada. */
    public function test_open_text_can_be_hidden(): void
    {
        $owner = $this->owner();
        $built = $this->publishedSurveyWithAllTypes($owner);

        $this->openSurvey($built['survey']);
        $this->submitAs($built['survey'], $this->validAnswers($built, 'Comentario privado'))
            ->assertOk();

        $component = Livewire::actingAs($owner)
            ->test(LiveDashboard::class, ['survey' => $built['survey']]);

        $component->assertSee('Comentario privado');

        $component->call('toggleOpenTextVisibility')
            ->assertSet('showOpenText', false)
            ->assertDontSee('Comentario privado');
    }

    /** La etiqueta dice "sesiones", nunca "personas" (API.md §5.2). */
    public function test_the_dashboard_says_sessions_not_people(): void
    {
        $owner = $this->owner();
        $built = $this->publishedSurveyWithAllTypes($owner);

        $component = Livewire::actingAs($owner)
            ->test(LiveDashboard::class, ['survey' => $built['survey']]);

        $component->assertSee('Sesiones conectadas');
        $component->assertDontSee('Personas conectadas');
    }
}
