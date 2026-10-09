<?php

namespace App\Livewire\Admin;

use App\Domain\Qr\QrCodeService;
use App\Domain\Surveys\InvalidTransitionException;
use App\Domain\Surveys\QuestionService;
use App\Domain\Surveys\SurveyService;
use App\Domain\Surveys\SurveyStateMachine;
use App\Domain\Surveys\SurveyValidator;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Survey;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Editor de encuesta y de preguntas (pantallas 4, 5, 6 y 7).
 *
 * ============== DOS NIVELES DE PERMISO, NO UNO ==============
 * `update`          — cabecera: titulo, descripcion, instrucciones. Permitido
 *                     en cualquier estado; corregir una errata con la encuesta
 *                     activa es seguro y util.
 * `updateStructure` — preguntas y opciones. SOLO en borrador o programada.
 *
 * Esa separacion es la politica de versionado del brief §4.2: la estructura se
 * congela al publicar, y para cambiarla se duplica como nueva version.
 * Permitir ediciones estructurales en caliente obligaria a versionar cada
 * pregunta y a decidir como agregar respuestas de versiones distintas.
 * ============================================================
 */
class SurveyEditor extends Component
{
    public Survey $survey;

    public string $title = '';
    public string $description = '';
    public string $instructions = '';
    public ?string $startsAt = null;
    public ?string $endsAt = null;

    public ?string $flash = null;
    public bool $showQr = false;

    /** @var array<int, string> errores de publicacion, por pregunta */
    public array $publishErrors = [];

    public function mount(Survey $survey): void
    {
        $this->authorize('view', $survey);

        $this->survey = $survey;
        $this->title = (string) $survey->title;
        $this->description = (string) $survey->description;
        $this->instructions = (string) $survey->instructions;
        $this->startsAt = $survey->starts_at?->format('Y-m-d\TH:i');
        $this->endsAt = $survey->ends_at?->format('Y-m-d\TH:i');
    }

    // ------------------------------------------------------------- cabecera

    public function save(SurveyService $surveys): void
    {
        $this->authorize('update', $this->survey);

        $limits = config('encuestalive.limits');

        // Limites aplicados en PHP: SQLite no valida longitudes de VARCHAR y
        // MySQL 8, que es el destino, si lo haria (DATABASE.md §10.1).
        $data = $this->validate([
            'title' => ['required', 'string', 'min:3', 'max:' . $limits['survey_title']],
            'description' => ['nullable', 'string', 'max:' . $limits['survey_description']],
            'instructions' => ['nullable', 'string', 'max:' . $limits['survey_instructions']],
            'startsAt' => ['nullable', 'date'],
            'endsAt' => ['nullable', 'date', 'after:startsAt'],
        ], [], [
            'title' => 'titulo',
            'startsAt' => 'fecha de inicio',
            'endsAt' => 'fecha de fin',
        ]);

        $surveys->update(Auth::user(), $this->survey, [
            'title' => $data['title'],
            'description' => $data['description'] ?: null,
            'instructions' => $data['instructions'] ?: null,
            'starts_at' => $data['startsAt'] ?: null,
            'ends_at' => $data['endsAt'] ?: null,
        ]);

        $this->survey->refresh();
        $this->flash = 'Cambios guardados.';
    }

    // ------------------------------------------------------------ preguntas

    public function addQuestion(string $type, QuestionService $questions): void
    {
        $this->authorize('updateStructure', $this->survey);

        $this->guarded(fn () => $questions->addQuestion($this->survey, [
            'type' => $type,
            'prompt' => 'Nueva pregunta',
            'is_required' => true,
        ]));
    }

    public function updatePrompt(int $questionId, string $prompt, QuestionService $questions): void
    {
        $this->authorize('updateStructure', $this->survey);

        $this->guarded(fn () => $questions->updateQuestion(
            $this->question($questionId),
            ['prompt' => $prompt],
        ));
    }

    public function toggleRequired(int $questionId, QuestionService $questions): void
    {
        $this->authorize('updateStructure', $this->survey);

        $question = $this->question($questionId);

        $this->guarded(fn () => $questions->updateQuestion(
            $question,
            ['is_required' => ! $question->is_required],
        ));
    }

    public function removeQuestion(int $questionId, QuestionService $questions): void
    {
        $this->authorize('updateStructure', $this->survey);

        $this->guarded(fn () => $questions->removeQuestion($this->question($questionId)));
    }

    public function addOption(int $questionId, QuestionService $questions): void
    {
        $this->authorize('updateStructure', $this->survey);

        $question = $this->question($questionId);
        $next = $question->options()->count() + 1;

        $this->guarded(fn () => $questions->addOption($question, 'Opcion ' . $next));
    }

    public function updateOption(int $optionId, string $label, QuestionService $questions): void
    {
        $this->authorize('updateStructure', $this->survey);

        $this->guarded(fn () => $questions->updateOption($this->option($optionId), $label));
    }

    public function removeOption(int $optionId, QuestionService $questions): void
    {
        $this->authorize('updateStructure', $this->survey);

        $this->guarded(fn () => $questions->removeOption($this->option($optionId)));
    }

    public function setSelectionLimits(int $questionId, ?int $min, ?int $max, QuestionService $questions): void
    {
        $this->authorize('updateStructure', $this->survey);

        $this->guarded(fn () => $questions->setSelectionLimits($this->question($questionId), $min, $max));
    }

    public function setTextLimits(int $questionId, ?int $min, ?int $max, QuestionService $questions): void
    {
        $this->authorize('updateStructure', $this->survey);

        $this->guarded(fn () => $questions->setTextLimits($this->question($questionId), $min, $max));
    }

    public function moveQuestion(int $questionId, int $direction, QuestionService $questions): void
    {
        $this->authorize('updateStructure', $this->survey);

        $ids = $this->survey->questions()->orderBy('position')->pluck('id')->all();
        $index = array_search($questionId, $ids, true);

        if ($index === false) {
            return;
        }

        $target = $index + $direction;
        if ($target < 0 || $target >= count($ids)) {
            return;
        }

        [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];

        $this->guarded(fn () => $questions->reorder($this->survey, $ids));
    }

    // ------------------------------------------------------- ciclo de vida

    public function publish(SurveyService $surveys): void
    {
        $this->authorize('publish', $this->survey);
        $this->publishErrors = [];

        try {
            $surveys->publish(Auth::user(), $this->survey);
            $this->survey->refresh();
            $this->showQr = true;
            $this->flash = 'Encuesta publicada. Ya puedes compartir su QR.';
        } catch (ValidationException $e) {
            // RN-01: la lista COMPLETA de problemas a la vez, no el primero.
            foreach ($e->errors() as $field => $messages) {
                $this->publishErrors[$field] = $messages[0];
                $this->addError($field, $messages[0]);
            }
        } catch (InvalidTransitionException $e) {
            $this->addError('status', $e->getMessage());
        }
    }

    public function pause(SurveyService $surveys): void
    {
        $this->applyTransition('pause', fn () => $surveys->pause(Auth::user(), $this->survey));
    }

    public function resume(SurveyService $surveys): void
    {
        $this->applyTransition('resume', fn () => $surveys->resume(Auth::user(), $this->survey));
    }

    public function close(SurveyService $surveys): void
    {
        $this->applyTransition('close', fn () => $surveys->close(Auth::user(), $this->survey));
    }

    public function archive(SurveyService $surveys): void
    {
        $this->applyTransition('archive', fn () => $surveys->archive(Auth::user(), $this->survey));
    }

    public function duplicate(SurveyService $surveys)
    {
        $this->authorize('duplicate', $this->survey);

        $copy = $surveys->duplicate(Auth::user(), $this->survey);

        return $this->redirectRoute('admin.surveys.edit', ['survey' => $copy->id], navigate: false);
    }

    private function applyTransition(string $ability, callable $action): void
    {
        $this->authorize($ability, $this->survey);

        try {
            $action();
            $this->survey->refresh();
            $this->flash = 'Estado actualizado.';
        } catch (InvalidTransitionException $e) {
            $this->addError('status', $e->getMessage());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }
        }
    }

    // ----------------------------------------------------------------- apoyo

    private function guarded(callable $action): void
    {
        try {
            $action();
            $this->survey->refresh();
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }
        } catch (InvalidTransitionException $e) {
            $this->addError('structure', $e->getMessage());
        }
    }

    /** Siempre dentro de ESTA encuesta: una pregunta ajena da 404. */
    private function question(int $questionId): Question
    {
        return Question::query()
            ->where('survey_id', $this->survey->id)
            ->where('id', $questionId)
            ->firstOrFail();
    }

    private function option(int $optionId): QuestionOption
    {
        return QuestionOption::query()
            ->where('id', $optionId)
            ->whereIn('question_id', $this->survey->questions()->select('id'))
            ->firstOrFail();
    }

    #[Layout('layouts.admin')]
    public function render(
        SurveyStateMachine $machine,
        SurveyValidator $validator,
        QrCodeService $qr,
    ): View {
        $this->survey->load(['questions.options']);

        return view('livewire.admin.survey-editor', [
            'machine' => $machine,
            'editable' => $machine->isStructurallyEditable($this->survey),
            'blockers' => $validator->publicationErrors($this->survey),
            'publicUrl' => $this->survey->public_id ? $qr->publicUrl($this->survey) : null,
            // `data:` en lugar de una peticion extra: el QR se pinta en cuanto
            // se publica, sin un segundo viaje al servidor ni un fichero
            // temporal que despues habria que limpiar.
            'qrDataUri' => $this->survey->public_id ? $qr->dataUri($this->survey, 320) : null,
        ]);
    }
}
