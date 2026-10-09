<?php

namespace App\Domain\Surveys;

use App\Models\Question;
use App\Models\Survey;

/**
 * Validacion de publicabilidad (PRD RN-01, las nueve condiciones).
 *
 * Esta clase existe separada de la maquina de estados porque responden a dos
 * preguntas distintas: la maquina dice si la TRANSICION es legal desde el
 * estado actual; esto dice si el CONTENIDO esta listo para ser publicado.
 *
 * RN-01 exige que el rechazo sea ATOMICO y que produzca la LISTA COMPLETA de
 * errores enlazados a cada pregunta. Por eso acumula y no corta en el primer
 * fallo: obligar a alguien a publicar nueve veces para descubrir nueve errores
 * de uno en uno es un mal producto.
 *
 * Un `CHECK` de base de datos no puede hacer esto, y ademas divergiria entre
 * SQLite y MySQL 8 (ADR-0003). La validacion en PHP no es una comodidad: es el
 * unico sitio donde el requisito se puede cumplir entero.
 */
class SurveyValidator
{
    /**
     * @return array<string, string> clave de anclaje => mensaje
     */
    public function publicationErrors(Survey $survey): array
    {
        $errors = [];
        $limits = config('encuestalive.limits');

        // RN-01.1 — titulo 3..150 tras recortar espacios.
        $title = trim((string) $survey->title);
        if (mb_strlen($title) < 3) {
            $errors['title'] = 'El titulo debe tener al menos 3 caracteres.';
        } elseif (mb_strlen($title) > $limits['survey_title']) {
            $errors['title'] = sprintf(
                'El titulo no puede superar los %d caracteres.',
                $limits['survey_title'],
            );
        }

        $questions = $survey->relationLoaded('questions')
            ? $survey->questions
            : $survey->questions()->with('options')->get();

        // RN-01.2 — al menos una pregunta.
        if ($questions->isEmpty()) {
            $errors['questions'] = 'Anade al menos una pregunta antes de publicar.';
        }

        // RN-01.9 — limite operativo de cordura (no tecnico).
        if ($questions->count() > $limits['max_questions']) {
            $errors['questions'] = sprintf(
                'Una encuesta no puede tener mas de %d preguntas.',
                $limits['max_questions'],
            );
        }

        foreach ($questions as $index => $question) {
            $key = 'question.' . $question->id;
            $position = $index + 1;

            // RN-01.3 — enunciado 3..300.
            $prompt = trim((string) $question->prompt);
            if (mb_strlen($prompt) < 3) {
                $errors[$key] = sprintf('Pregunta %d: escribe un enunciado de al menos 3 caracteres.', $position);

                continue;
            }
            if (mb_strlen($prompt) > $limits['question_prompt']) {
                $errors[$key] = sprintf(
                    'Pregunta %d: el enunciado no puede superar los %d caracteres.',
                    $position,
                    $limits['question_prompt'],
                );

                continue;
            }

            if ($question->isChoiceBased()) {
                $error = $this->choiceQuestionError($question, $position);
                if ($error !== null) {
                    $errors[$key] = $error;
                }

                continue;
            }

            // RN-01.6 — 0 <= min < max <= 2000.
            $min = (int) ($question->min_length ?? 0);
            $max = (int) ($question->max_length ?? $limits['free_text_default_max']);

            if ($min < 0 || $min >= $max || $max > $limits['free_text_max']) {
                $errors[$key] = sprintf(
                    'Pregunta %d: los limites de longitud deben cumplir 0 <= minimo < maximo <= %d.',
                    $position,
                    $limits['free_text_max'],
                );
            }
        }

        // RN-01.7 — coherencia de fechas.
        $dateError = $this->dateError($survey);
        if ($dateError !== null) {
            $errors['dates'] = $dateError;
        }

        return $errors;
    }

    private function choiceQuestionError(Question $question, int $position): ?string
    {
        $limits = config('encuestalive.limits');
        $options = $question->relationLoaded('options')
            ? $question->options
            : $question->options()->get();

        // RN-01.4 — >= 2 opciones, con etiqueta no vacia.
        if ($options->count() < 2) {
            return sprintf('Pregunta %d: una pregunta de seleccion necesita al menos 2 opciones.', $position);
        }

        $labels = [];
        foreach ($options as $option) {
            $label = trim((string) $option->label);

            if ($label === '') {
                return sprintf('Pregunta %d: hay una opcion sin texto.', $position);
            }

            if (mb_strlen($label) > $limits['option_label']) {
                return sprintf(
                    'Pregunta %d: una opcion supera los %d caracteres.',
                    $position,
                    $limits['option_label'],
                );
            }

            // RN-01.4 — sin duplicados dentro de la pregunta. Se compara en
            // minusculas: "Si" y "si" son indistinguibles para quien responde.
            $normalized = mb_strtolower($label);
            if (in_array($normalized, $labels, true)) {
                return sprintf('Pregunta %d: la opcion "%s" esta repetida.', $position, $label);
            }
            $labels[] = $normalized;
        }

        // RN-01.5 — 1 <= min <= max <= nº de opciones.
        if ($question->type === Question::TYPE_MULTIPLE_CHOICE) {
            $min = (int) ($question->min_selections ?? 1);
            $max = (int) ($question->max_selections ?? $options->count());

            if ($min < 1 || $min > $max || $max > $options->count()) {
                return sprintf(
                    'Pregunta %d: los limites de seleccion deben cumplir 1 <= minimo <= maximo <= %d.',
                    $position,
                    $options->count(),
                );
            }
        }

        return null;
    }

    private function dateError(Survey $survey): ?string
    {
        $starts = $survey->starts_at;
        $ends = $survey->ends_at;

        if ($starts !== null && $ends !== null && $starts->gte($ends)) {
            return 'La fecha de inicio debe ser anterior a la de fin.';
        }

        if ($ends !== null && $ends->isPast()) {
            return 'La fecha de fin ya paso. Ajustala antes de publicar.';
        }

        return null;
    }
}
