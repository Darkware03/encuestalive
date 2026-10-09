<?php

namespace App\Domain\Participation;

use App\Models\Question;
use App\Models\Survey;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Validacion de un envio contra el esquema REAL de la encuesta.
 *
 * ============ SE VALIDA CONTRA LA BASE DE DATOS, NO CONTRA EL CLIENTE ============
 * Nada de lo que afirme la peticion se da por bueno. Los tipos de pregunta,
 * los limites de seleccion, las longitudes y la pertenencia de cada opcion se
 * LEEN de la base de datos en el momento de validar.
 *
 * Esto importa porque el formulario publico es HTML que cualquiera puede
 * editar: un participante puede inventarse un `question_id` de otra encuesta,
 * marcar 7 opciones donde el maximo es 2, o enviar 5000 caracteres en un campo
 * de 200. Las tres cosas se rechazan aqui, en el servidor, con 422.
 * ================================================================================
 *
 * Nota sobre longitudes (DATABASE.md §10.1): SQLite NO aplica longitudes de
 * VARCHAR. Sin esta validacion en PHP, un texto de 5000 caracteres se guardaria
 * sin protestar en desarrollo y fallaria en MySQL 8, es decir, en produccion.
 */
class AnswerValidator
{
    /**
     * @param  array<int, array{question_id?: mixed, option_ids?: mixed, text?: mixed}>  $answers
     * @return array<int, array{question: Question, option_ids: array<int,int>, text: ?string}>
     *
     * @throws ValidationException
     */
    public function validate(Survey $survey, array $answers): array
    {
        /** @var Collection<int, Question> $questions */
        $questions = $survey->questions()->with('options')->get()->keyBy('id');

        if ($questions->isEmpty()) {
            throw ValidationException::withMessages([
                'answers' => 'Esta encuesta no tiene preguntas.',
            ]);
        }

        if ($answers === []) {
            throw ValidationException::withMessages([
                'answers' => 'No has respondido ninguna pregunta.',
            ]);
        }

        if (count($answers) > 100) {
            throw ValidationException::withMessages([
                'answers' => 'El envio contiene demasiadas respuestas.',
            ]);
        }

        $errors = [];
        $validated = [];
        $seenQuestionIds = [];

        foreach (array_values($answers) as $index => $answer) {
            $key = 'answers.' . $index;
            $questionId = (int) ($answer['question_id'] ?? 0);

            $question = $questions->get($questionId);

            // Una pregunta ajena a esta encuesta es un 422 EXPLICITO, nunca un
            // descarte en silencio: ignorarla haria creer al participante que
            // su respuesta se guardo (API.md §2.5).
            if ($question === null) {
                $errors[$key . '.question_id'] = ['Esa pregunta no pertenece a esta encuesta.'];

                continue;
            }

            if (isset($seenQuestionIds[$questionId])) {
                $errors[$key . '.question_id'] = ['Has respondido dos veces a la misma pregunta.'];

                continue;
            }
            $seenQuestionIds[$questionId] = true;

            $result = $question->isChoiceBased()
                ? $this->validateChoice($question, $answer, $key, $errors)
                : $this->validateText($question, $answer, $key, $errors);

            if ($result !== null) {
                $validated[] = $result;
            }
        }

        // RN-13: todas las obligatorias deben venir respondidas.
        foreach ($questions as $question) {
            if ($question->is_required && ! isset($seenQuestionIds[$question->id])) {
                $errors['answers.question_' . $question->id] = [
                    sprintf('La pregunta "%s" es obligatoria.', $this->shorten($question->prompt)),
                ];
            }
        }

        if ($errors !== []) {
            // RN-12: validacion ATOMICA. O entra el envio entero o no entra
            // nada; no existen envios parciales en `responses`.
            throw ValidationException::withMessages($errors);
        }

        return $validated;
    }

    /**
     * @return array{question: Question, option_ids: array<int,int>, text: null}|null
     */
    private function validateChoice(Question $question, array $answer, string $key, array &$errors): ?array
    {
        $raw = $answer['option_ids'] ?? [];
        if (! is_array($raw)) {
            $raw = [$raw];
        }

        $optionIds = array_values(array_filter(array_map(
            fn ($id) => is_numeric($id) ? (int) $id : null,
            $raw,
        ), fn ($id) => $id !== null && $id > 0));

        // Sin duplicados: marcar dos veces la misma opcion no la hace contar
        // doble, y el UNIQUE de response_selections lo rechazaria igualmente.
        $unique = array_values(array_unique($optionIds));
        if (count($unique) !== count($optionIds)) {
            $errors[$key . '.option_ids'] = ['Has marcado la misma opcion mas de una vez.'];

            return null;
        }

        // Pertenencia real: la opcion debe ser de ESTA pregunta.
        $valid = $question->options->pluck('id')->map(fn ($id) => (int) $id)->all();
        $foreign = array_diff($unique, $valid);

        if ($foreign !== []) {
            $errors[$key . '.option_ids'] = ['Alguna de las opciones no pertenece a esta pregunta.'];

            return null;
        }

        $count = count($unique);

        if ($question->type === Question::TYPE_SINGLE_CHOICE) {
            if ($question->is_required && $count !== 1) {
                $errors[$key . '.option_ids'] = ['Selecciona exactamente una opcion.'];

                return null;
            }
            if (! $question->is_required && $count > 1) {
                $errors[$key . '.option_ids'] = ['Solo puedes seleccionar una opcion.'];

                return null;
            }
        } else {
            // CA-05: limites de seleccion multiple, leidos de la BD.
            $min = (int) ($question->min_selections ?? 1);
            $max = (int) ($question->max_selections ?? count($valid));

            // Una pregunta opcional sin marcar nada es legitima: no hay fila.
            if ($count === 0 && ! $question->is_required) {
                return null;
            }

            if ($count < $min) {
                $errors[$key . '.option_ids'] = [
                    $min === 1
                        ? ['Selecciona al menos una opcion.'][0]
                        : sprintf('Selecciona al menos %d opciones.', $min),
                ];

                return null;
            }

            if ($count > $max) {
                $errors[$key . '.option_ids'] = [
                    sprintf('Selecciona como maximo %d %s.', $max, $max === 1 ? 'opcion' : 'opciones'),
                ];

                return null;
            }
        }

        if ($count === 0) {
            return null;
        }

        return ['question' => $question, 'option_ids' => $unique, 'text' => null];
    }

    /**
     * @return array{question: Question, option_ids: array<int,int>, text: string}|null
     */
    private function validateText(Question $question, array $answer, string $key, array &$errors): ?array
    {
        $raw = $answer['text'] ?? null;

        if (! is_string($raw)) {
            $raw = $raw === null ? '' : (string) $raw;
        }

        /*
         * Se recorta el espacio de los extremos, y NADA MAS. El contenido se
         * guarda EN CRUDO: sanear en la entrada destruye el dato original del
         * participante y da falsa seguridad. El escapado correcto es el de
         * salida, que Blade hace con `{{ }}` (ARCHITECTURE.md §3.2, CA-06).
         */
        $text = trim($raw);
        $min = (int) ($question->min_length ?? 0);
        $max = (int) ($question->max_length ?? config('encuestalive.limits.free_text_default_max'));

        if ($text === '') {
            if ($question->is_required) {
                $errors[$key . '.text'] = ['Esta pregunta es obligatoria.'];
            }

            return null;
        }

        $length = mb_strlen($text);

        if ($length < $min) {
            $errors[$key . '.text'] = [sprintf('Escribe al menos %d caracteres.', $min)];

            return null;
        }

        // El limite se aplica en PHP porque SQLite no lo haria (DATABASE.md §10.1).
        if ($length > $max) {
            $errors[$key . '.text'] = [sprintf('El texto no puede superar los %d caracteres.', $max)];

            return null;
        }

        return ['question' => $question, 'option_ids' => [], 'text' => $text];
    }

    private function shorten(string $prompt, int $limit = 60): string
    {
        return mb_strlen($prompt) > $limit
            ? mb_substr($prompt, 0, $limit - 1) . '…'
            : $prompt;
    }
}
