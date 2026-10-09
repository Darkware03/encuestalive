<?php

namespace App\Domain\Stats;

use App\Models\Question;

/**
 * Metricas agregadas de UNA pregunta (PRD §8.6, D-06).
 *
 * El punto delicado es la BASE DEL PORCENTAJE en seleccion multiple. D-06 es
 * vinculante:
 *
 *   · Base por defecto: selecciones(opcion) / participantes_en_la_pregunta.
 *   · PUEDE SUPERAR EL 100 %, y eso no es un fallo: es lo que significa que la
 *     gente marque varias opciones.
 *   · La interfaz DEBE declarar siempre el denominador en pantalla.
 *
 * Por eso este DTO expone `denominatorLabel()`: la frase que la pantalla tiene
 * obligacion de mostrar. Si el dato viaja con su explicacion pegada, es mucho
 * mas dificil pintarlo sin ella.
 */
final class QuestionStats
{
    public const BASIS_PARTICIPANTS = 'participants';
    public const BASIS_SELECTIONS = 'selections';

    /**
     * @param  array<int, OptionStats>  $options
     */
    public function __construct(
        public readonly int $questionId,
        public readonly string $type,
        public readonly string $prompt,
        public readonly bool $isRequired,
        /** Envios completados que respondieron ESTA pregunta. Denominador base A. */
        public readonly int $answeredCount,
        /** Suma de todas las marcas de la pregunta. Denominador base B. */
        public readonly int $totalSelections,
        /** Envios completados que dejaron la pregunta sin responder (opcionales). */
        public readonly int $skippedCount,
        public readonly array $options = [],
        /** Solo free_text: numero de respuestas con texto no vacio. */
        public readonly int $textAnswersCount = 0,
    ) {}

    public function isChoiceBased(): bool
    {
        return in_array($this->type, Question::CHOICE_TYPES, true);
    }

    public function isMultiple(): bool
    {
        return $this->type === Question::TYPE_MULTIPLE_CHOICE;
    }

    public function basis(): string
    {
        // D-06: en multiple la base por defecto es SOBRE PARTICIPANTES.
        return $this->isMultiple() ? self::BASIS_PARTICIPANTS : self::BASIS_SELECTIONS;
    }

    /**
     * La frase que la pantalla esta OBLIGADA a mostrar junto al grafico
     * (D-06, PRD §8.6: "siempre visible y no en un tooltip").
     */
    public function denominatorLabel(): string
    {
        if ($this->answeredCount === 0) {
            return 'Sin respuestas todavia.';
        }

        if ($this->isMultiple()) {
            return sprintf(
                '%% sobre los %d participantes que respondieron esta pregunta. '
                . 'La suma puede superar el 100 %% porque se admiten varias selecciones.',
                $this->answeredCount,
            );
        }

        return sprintf(
            '%% sobre las %d respuestas a esta pregunta.',
            $this->answeredCount,
        );
    }

    /** Etiqueta legible del tipo, tambien usada como valor del CSV. */
    public function typeLabel(): string
    {
        return match ($this->type) {
            Question::TYPE_SINGLE_CHOICE => 'seleccion_unica',
            Question::TYPE_MULTIPLE_CHOICE => 'seleccion_multiple',
            Question::TYPE_FREE_TEXT => 'texto_libre',
            default => $this->type,
        };
    }

    public function toBroadcastArray(): array
    {
        $payload = [
            'question_id' => $this->questionId,
            'type' => $this->type,
            'answered_count' => $this->answeredCount,
        ];

        if ($this->isMultiple()) {
            $payload['percentage_basis'] = self::BASIS_PARTICIPANTS;
        }

        if ($this->isChoiceBased()) {
            $payload['options'] = array_map(
                fn (OptionStats $o) => $o->toBroadcastArray(),
                $this->options,
            );
        }

        return $payload;
    }
}
