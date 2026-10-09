<?php

namespace App\Domain\Surveys;

use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Survey;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edicion de la estructura de una encuesta (API.md §4.2).
 *
 * ================= ESTRUCTURA CONGELADA AL PUBLICAR =================
 * Toda operacion de esta clase empieza llamando a `assertEditable()`.
 *
 * La estructura es INMUTABLE una vez publicada (brief §4.2). El motivo no es
 * pereza: permitir ediciones estructurales en caliente obligaria a versionar
 * preguntas y opciones y a decidir como agregar respuestas dadas sobre
 * versiones distintas de la misma pregunta. Para un MVP eso es complejidad
 * desproporcionada, y el resultado serian graficos que mezclan preguntas que
 * ya no significan lo mismo.
 *
 * La alternativa esta implementada y es de primera clase:
 * SurveyService::duplicate() crea una nueva version editable.
 * ====================================================================
 */
class QuestionService
{
    public function __construct(
        private readonly SurveyStateMachine $states,
    ) {}

    public function addQuestion(Survey $survey, array $attributes): Question
    {
        $this->assertEditable($survey);

        $type = (string) ($attributes['type'] ?? Question::TYPE_SINGLE_CHOICE);

        if (! in_array($type, Question::TYPES, true)) {
            throw ValidationException::withMessages([
                'type' => 'Tipo de pregunta desconocido.',
            ]);
        }

        $limits = config('encuestalive.limits');

        if ($survey->questions()->count() >= $limits['max_questions']) {
            throw ValidationException::withMessages([
                'questions' => sprintf(
                    'Has alcanzado el limite de %d preguntas por encuesta.',
                    $limits['max_questions'],
                ),
            ]);
        }

        $prompt = $this->boundedText(
            $attributes['prompt'] ?? '',
            $limits['question_prompt'],
            'prompt',
            'El enunciado',
        );

        return DB::transaction(function () use ($survey, $type, $prompt, $attributes, $limits) {
            $question = Question::create([
                'survey_id' => $survey->id,
                'type' => $type,
                'prompt' => $prompt,
                'is_required' => (bool) ($attributes['is_required'] ?? true),
                'position' => $this->nextPosition($survey),
                // Valores por defecto coherentes con RN-01.5 y RN-01.6. Una
                // pregunta recien creada debe ser publicable sin que nadie
                // tenga que recordar rellenar cuatro campos numericos.
                'min_selections' => $type === Question::TYPE_MULTIPLE_CHOICE ? 1 : null,
                'max_selections' => null,
                'min_length' => $type === Question::TYPE_FREE_TEXT ? 0 : null,
                'max_length' => $type === Question::TYPE_FREE_TEXT
                    ? $limits['free_text_default_max']
                    : null,
            ]);

            // Una pregunta cerrada nace con dos opciones vacias: es el minimo
            // que RN-01.4 exige, y asi el editor muestra desde el principio la
            // forma que la pregunta tendra que tener.
            if ($question->isChoiceBased()) {
                foreach ([1, 2] as $position) {
                    QuestionOption::create([
                        'question_id' => $question->id,
                        'label' => 'Opcion ' . $position,
                        'position' => $position,
                    ]);
                }
            }

            return $question->load('options');
        });
    }

    public function updateQuestion(Question $question, array $attributes): Question
    {
        $this->assertEditable($question->survey);

        $limits = config('encuestalive.limits');

        if (array_key_exists('prompt', $attributes)) {
            $question->prompt = $this->boundedText(
                $attributes['prompt'],
                $limits['question_prompt'],
                'prompt',
                'El enunciado',
            );
        }

        if (array_key_exists('is_required', $attributes)) {
            $question->is_required = (bool) $attributes['is_required'];
        }

        $question->save();

        return $question;
    }

    public function removeQuestion(Question $question): void
    {
        $this->assertEditable($question->survey);

        $survey = $question->survey;
        $question->delete();

        // Las posiciones se recompactan: un hueco en la secuencia rompe el
        // UNIQUE(survey_id, position) en el siguiente alta.
        $this->recompactPositions($survey);
    }

    public function addOption(Question $question, string $label): QuestionOption
    {
        $this->assertEditable($question->survey);

        if (! $question->isChoiceBased()) {
            throw ValidationException::withMessages([
                'options' => 'Una pregunta de texto libre no tiene opciones.',
            ]);
        }

        $limits = config('encuestalive.limits');

        if ($question->options()->count() >= $limits['max_options_per_question']) {
            throw ValidationException::withMessages([
                'options' => sprintf(
                    'Una pregunta no puede tener mas de %d opciones.',
                    $limits['max_options_per_question'],
                ),
            ]);
        }

        $option = QuestionOption::create([
            'question_id' => $question->id,
            'label' => $this->boundedText($label, $limits['option_label'], 'options', 'La opcion'),
            'position' => (int) $question->options()->max('position') + 1,
        ]);

        $this->clampSelectionLimits($question->refresh());

        return $option;
    }

    public function updateOption(QuestionOption $option, string $label): QuestionOption
    {
        $this->assertEditable($option->question->survey);

        $option->label = $this->boundedText(
            $label,
            (int) config('encuestalive.limits.option_label'),
            'options',
            'La opcion',
        );
        $option->save();

        return $option;
    }

    public function removeOption(QuestionOption $option): void
    {
        $question = $option->question;
        $this->assertEditable($question->survey);

        $option->delete();

        $position = 1;
        foreach ($question->options()->orderBy('position')->get() as $remaining) {
            $remaining->forceFill(['position' => $position++])->save();
        }

        // Quitar opciones puede dejar `max_selections` por encima del numero
        // de opciones y volver la pregunta impublicable sin avisar. Se ajusta.
        $this->clampSelectionLimits($question->refresh());
    }

    /**
     * RN-01.5: 1 <= min <= max <= nº de opciones. Se valida AQUI y ademas en
     * SurveyValidator al publicar: la doble comprobacion es intencionada,
     * porque el numero de opciones puede cambiar despues de fijar los limites.
     */
    public function setSelectionLimits(Question $question, ?int $min, ?int $max): Question
    {
        $this->assertEditable($question->survey);

        if ($question->type !== Question::TYPE_MULTIPLE_CHOICE) {
            throw ValidationException::withMessages([
                'min_selections' => 'Los limites de seleccion solo aplican a la seleccion multiple.',
            ]);
        }

        $optionCount = $question->options()->count();
        $min ??= 1;
        $max ??= $optionCount;

        if ($min < 1 || $min > $max || $max > $optionCount) {
            throw ValidationException::withMessages([
                'min_selections' => sprintf(
                    'Debe cumplirse 1 <= minimo <= maximo <= %d.',
                    $optionCount,
                ),
            ]);
        }

        $question->forceFill([
            'min_selections' => $min,
            'max_selections' => $max,
        ])->save();

        return $question;
    }

    /** RN-01.6: 0 <= min < max <= 2000. */
    public function setTextLimits(Question $question, ?int $min, ?int $max): Question
    {
        $this->assertEditable($question->survey);

        if ($question->type !== Question::TYPE_FREE_TEXT) {
            throw ValidationException::withMessages([
                'max_length' => 'Los limites de longitud solo aplican al texto libre.',
            ]);
        }

        $limits = config('encuestalive.limits');
        $min ??= 0;
        $max ??= $limits['free_text_default_max'];

        if ($min < 0 || $min >= $max || $max > $limits['free_text_max']) {
            throw ValidationException::withMessages([
                'max_length' => sprintf(
                    'Debe cumplirse 0 <= minimo < maximo <= %d.',
                    $limits['free_text_max'],
                ),
            ]);
        }

        $question->forceFill([
            'min_length' => $min,
            'max_length' => $max,
        ])->save();

        return $question;
    }

    /**
     * Reordenar. `ordered_ids` debe contener EXACTAMENTE los ids actuales: ni
     * uno de otra encuesta, ni uno de menos. Comprobarlo evita que un cliente
     * manipulado mueva una pregunta ajena a esta encuesta.
     *
     * @param  array<int, int>  $orderedIds
     */
    public function reorder(Survey $survey, array $orderedIds): void
    {
        $this->assertEditable($survey);

        $current = $survey->questions()->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $incoming = collect($orderedIds)->map(fn ($id) => (int) $id)->sort()->values()->all();

        if ($current !== $incoming) {
            throw ValidationException::withMessages([
                'order' => 'El nuevo orden debe contener exactamente las preguntas actuales.',
            ]);
        }

        DB::transaction(function () use ($orderedIds, $survey): void {
            /*
             * Dos pasadas. UNIQUE(survey_id, position) rechazaria una
             * asignacion directa en cuanto dos preguntas compartieran posicion
             * a mitad del bucle, aunque el estado final fuera valido. Se
             * aparcan primero en un rango imposible de alcanzar.
             */
            $offset = 10_000;
            foreach ($orderedIds as $index => $id) {
                Question::query()
                    ->where('survey_id', $survey->id)
                    ->where('id', $id)
                    ->update(['position' => $offset + $index]);
            }

            foreach ($orderedIds as $index => $id) {
                Question::query()
                    ->where('survey_id', $survey->id)
                    ->where('id', $id)
                    ->update(['position' => $index + 1]);
            }
        });
    }

    // ----------------------------------------------------------------- apoyo

    private function assertEditable(Survey $survey): void
    {
        if (! $this->states->isStructurallyEditable($survey)) {
            throw new InvalidTransitionException(
                $survey->status,
                $survey->status,
                'La estructura queda congelada al publicar. Duplica la encuesta '
                . 'como nueva version para cambiar sus preguntas.',
            );
        }
    }

    /**
     * Aplica el limite de longitud EN PHP.
     *
     * DATABASE.md §10.1 lo marca como obligatorio y explica por que: SQLite no
     * aplica longitudes de VARCHAR, asi que un texto de 5000 caracteres entra
     * tan tranquilo en un `string(500)` aqui y revienta en MySQL 8, que es el
     * motor de destino declarado. En desarrollo esta es la unica barrera real.
     */
    private function boundedText(mixed $value, int $max, string $field, string $label): string
    {
        $text = trim((string) $value);

        if ($text === '') {
            throw ValidationException::withMessages([
                $field => $label . ' no puede estar vacio.',
            ]);
        }

        if (mb_strlen($text) > $max) {
            throw ValidationException::withMessages([
                $field => sprintf('%s no puede superar los %d caracteres.', $label, $max),
            ]);
        }

        return $text;
    }

    private function nextPosition(Survey $survey): int
    {
        return (int) $survey->questions()->max('position') + 1;
    }

    private function recompactPositions(Survey $survey): void
    {
        $position = 1;
        foreach ($survey->questions()->orderBy('position')->get() as $question) {
            if ($question->position !== $position) {
                $question->forceFill(['position' => $position])->save();
            }
            $position++;
        }
    }

    private function clampSelectionLimits(Question $question): void
    {
        if ($question->type !== Question::TYPE_MULTIPLE_CHOICE) {
            return;
        }

        $count = $question->options()->count();
        $min = max(1, min((int) ($question->min_selections ?? 1), max(1, $count)));
        $max = min(max($min, (int) ($question->max_selections ?? $count)), max(1, $count));

        $question->forceFill([
            'min_selections' => $min,
            'max_selections' => $max,
        ])->save();
    }
}
