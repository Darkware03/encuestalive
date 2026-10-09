<?php

namespace App\Livewire\Admin;

use App\Domain\Stats\StatsService;
use App\Domain\Surveys\SurveyService;
use App\Domain\Surveys\SurveyStateMachine;
use App\Models\Survey;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Panel y listado de encuestas (pantallas 2, 3 y 13 del brief §3.4).
 *
 * Toda accion de estado vuelve a comprobar la policy EN EL SERVIDOR mediante
 * `$this->authorize()`. Que la interfaz oculte un boton no es control de
 * acceso: una llamada Livewire manipulada llega igual (API.md §4).
 */
class SurveyList extends Component
{
    use WithPagination;

    #[Url(as: 'estado')]
    public string $statusFilter = '';

    #[Url(as: 'q')]
    public string $search = '';

    public bool $archived = false;

    public ?string $flash = null;

    public function mount(bool $archived = false): void
    {
        $this->archived = $archived;
    }

    // ------------------------------------------------------------- acciones

    public function createSurvey(SurveyService $surveys)
    {
        $this->authorize('create', Survey::class);

        $survey = $surveys->create(Auth::user(), [
            'title' => 'Encuesta sin titulo',
        ]);

        return $this->redirectRoute('admin.surveys.edit', ['survey' => $survey->id], navigate: false);
    }

    public function publish(int $surveyId, SurveyService $surveys): void
    {
        $this->runTransition($surveyId, 'publish', fn (Survey $s) => $surveys->publish(Auth::user(), $s));
    }

    public function pause(int $surveyId, SurveyService $surveys): void
    {
        $this->runTransition($surveyId, 'pause', fn (Survey $s) => $surveys->pause(Auth::user(), $s));
    }

    public function resume(int $surveyId, SurveyService $surveys): void
    {
        $this->runTransition($surveyId, 'resume', fn (Survey $s) => $surveys->resume(Auth::user(), $s));
    }

    public function close(int $surveyId, SurveyService $surveys): void
    {
        $this->runTransition($surveyId, 'close', fn (Survey $s) => $surveys->close(Auth::user(), $s));
    }

    public function archive(int $surveyId, SurveyService $surveys): void
    {
        $this->runTransition($surveyId, 'archive', fn (Survey $s) => $surveys->archive(Auth::user(), $s));
    }

    public function duplicate(int $surveyId, SurveyService $surveys)
    {
        $survey = $this->findOwned($surveyId);
        $this->authorize('duplicate', $survey);

        $copy = $surveys->duplicate(Auth::user(), $survey);

        return $this->redirectRoute('admin.surveys.edit', ['survey' => $copy->id], navigate: false);
    }

    public function deleteSurvey(int $surveyId, SurveyService $surveys): void
    {
        $survey = $this->findOwned($surveyId);
        $this->authorize('delete', $survey);

        $surveys->delete(Auth::user(), $survey);
        $this->flash = 'Borrador eliminado.';
    }

    /**
     * Ejecuta una transicion comprobando SIEMPRE la policy antes.
     *
     * `InvalidTransitionException` se convierte en un error de formulario y no
     * en un 500: intentar reabrir una encuesta cerrada es un conflicto
     * legitimo que merece un mensaje, no una pantalla de error.
     */
    private function runTransition(int $surveyId, string $ability, callable $action): void
    {
        $survey = $this->findOwned($surveyId);
        $this->authorize($ability, $survey);

        try {
            $action($survey);
            $this->flash = 'Encuesta actualizada.';
        } catch (\App\Domain\Surveys\InvalidTransitionException $e) {
            $this->addError('status', $e->getMessage());
        } catch (\Illuminate\Validation\ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }
        }
    }

    /**
     * Resuelve la encuesta SIEMPRE dentro del ambito del propietario.
     *
     * Devolver 404 y no 403 para una encuesta ajena evita confirmar que ese id
     * existe (ARCHITECTURE.md §8.3).
     */
    private function findOwned(int $surveyId): Survey
    {
        return Survey::query()
            ->where('id', $surveyId)
            ->where('created_by_user_id', Auth::id())
            ->firstOrFail();
    }

    // -------------------------------------------------------------- render

    #[Layout('layouts.admin')]
    public function render(StatsService $stats, SurveyStateMachine $machine): View
    {
        $query = Survey::query()
            ->where('created_by_user_id', Auth::id())
            ->withCount('responses')
            ->orderByDesc('created_at');

        // RN-10.2: las archivadas no aparecen en el listado por defecto.
        $this->archived ? $query->archived() : $query->notArchived();

        if ($this->statusFilter !== '') {
            $query->where('status', $this->statusFilter);
        }

        if (trim($this->search) !== '') {
            // `like` con comodin por ambos lados. Su sensibilidad a
            // mayusculas DIVERGE entre SQLite y MySQL 8 (DATABASE.md §10.1);
            // se normaliza en PHP para que el comportamiento sea el mismo en
            // los dos motores.
            $term = '%' . mb_strtolower(trim($this->search)) . '%';
            $query->whereRaw('lower(title) like ?', [$term]);
        }

        $surveys = $query->paginate(10);

        // Resumen del panel. Sale de StatsService, no de un COUNT suelto aqui:
        // D-07 exige una sola fuente para estas cifras.
        $active = Survey::query()
            ->where('created_by_user_id', Auth::id())
            ->where('status', Survey::STATUS_ACTIVE)
            ->get();

        $liveStats = $active->map(fn (Survey $s) => $stats->snapshot($s));

        return view('livewire.admin.survey-list', [
            'surveys' => $surveys,
            'machine' => $machine,
            'activeCount' => $active->count(),
            'connectedTotal' => $liveStats->sum(fn ($s) => $s->connectedSessions),
            'completedTotal' => $liveStats->sum(fn ($s) => $s->completedResponses),
            'statuses' => Survey::STATUSES,
        ]);
    }
}
