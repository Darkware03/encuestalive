<?php

namespace App\Domain\Export;

use App\Domain\Stats\OptionStats;
use App\Domain\Stats\QuestionStats;
use App\Domain\Stats\StatsService;
use App\Models\Question;
use App\Models\Response;
use App\Models\Survey;
use Generator;

/**
 * Exportacion CSV de resultados (API.md §3.2, CA-11, CA-14).
 *
 * ================= LA GARANTIA DE PRIVACIDAD ES UNA LISTA BLANCA =================
 * Este exportador NO recibe modelos y decide que ocultar. Construye cada fila
 * nombrando EXPLICITAMENTE las seis columnas que salen. Es la diferencia entre
 * "me acorde de excluir la IP" y "la IP no tiene por donde entrar".
 *
 * Jamas se consulta `security_events`, que es la unica tabla del modelo con
 * direcciones IP, ni se toca ninguna columna de red. La consulta que uniria
 * una respuesta con una IP ni siquiera se puede escribir: no hay par de
 * columnas por el que hacer el JOIN (DATABASE.md §4.1).
 *
 * NUNCA se exporta: IP, user-agent, token de sesion o su hash,
 * `participant_session_id`, id interno de respuesta, `correlation_id`, nada de
 * `security_events` y ningun dato del administrador.
 * ================================================================================
 *
 * `respuesta_n` es un ordinal LOCAL DEL FICHERO, reasignado 1..N en cada
 * exportacion. Agrupa las filas de un mismo participante para poder analizar
 * correlaciones entre preguntas, pero NO es un identificador estable: cambia
 * entre exportaciones y no permite reidentificar a nadie ni cruzar con otra
 * encuesta.
 */
class CsvExporter
{
    /** Formato largo: una fila por respuesta a pregunta. */
    public const DETAIL_HEADERS = [
        'respuesta_n',
        'enviada_en',
        'pregunta_orden',
        'pregunta',
        'tipo',
        'valor',
    ];

    /**
     * Resumen agregado. D-06 obliga a exportar AMBAS bases de porcentaje en
     * columnas separadas, "para que nadie tenga que adivinar" cual se uso.
     */
    public const SUMMARY_HEADERS = [
        'pregunta_orden',
        'pregunta',
        'tipo',
        'opcion',
        'cantidad',
        'pct_sobre_participantes',
        'pct_sobre_selecciones',
        'participantes_en_la_pregunta',
        'selecciones_totales',
    ];

    public function __construct(
        private readonly CsvInjectionSanitizer $sanitizer,
        private readonly StatsService $stats,
    ) {}

    /**
     * Genera el fichero por trozos, sin cargar todo en memoria.
     *
     * @return Generator<int, string>
     */
    public function stream(Survey $survey, bool $includeOpenText = true): Generator
    {
        // BOM UTF-8: sin el, Excel en Windows interpreta el fichero como
        // ANSI y destroza cualquier tilde o enye. Es un detalle tonto que
        // arruina la exportacion para quien la va a abrir de verdad.
        yield "\xEF\xBB\xBF";

        yield from $this->detailSection($survey, $includeOpenText);

        yield "\r\n";

        yield from $this->summarySection($survey);
    }

    /**
     * @return Generator<int, string>
     */
    private function detailSection(Survey $survey, bool $includeOpenText): Generator
    {
        yield $this->sanitizer->row(self::DETAIL_HEADERS);

        $questions = $survey->questions()->with('options')->orderBy('position')->get()->keyBy('id');

        if ($questions->isEmpty()) {
            return;
        }

        $ordinal = 0;

        /*
         * Cursor en lugar de `get()`: una encuesta con 300 participantes y 10
         * preguntas son 3000 filas, y cargarlas todas para despues escribirlas
         * de una en una no tiene ninguna ventaja.
         *
         * `select` explicito: ni siquiera se trae `participant_session_id` a
         * memoria. No se puede filtrar por error algo que no se ha leido.
         */
        $responses = Response::query()
            ->where('survey_id', $survey->id)
            ->orderBy('completed_at')
            ->orderBy('id')
            ->select(['id', 'completed_at'])
            ->cursor();

        foreach ($responses as $response) {
            $ordinal++;

            $answers = $response->answers()
                ->with('selections.option')
                ->get();

            foreach ($answers as $answer) {
                $question = $questions->get($answer->question_id);

                if ($question === null) {
                    continue;
                }

                $values = $this->valuesFor($question, $answer, $includeOpenText);

                foreach ($values as $value) {
                    yield $this->sanitizer->row([
                        $ordinal,
                        $response->completed_at?->toIso8601ZuluString(),
                        $question->position,
                        $question->prompt,
                        $this->typeLabel($question->type),
                        $value,
                    ]);
                }
            }
        }
    }

    /**
     * Una fila por opcion marcada. En seleccion multiple eso significa varias
     * filas para el mismo participante y la misma pregunta, que es lo correcto
     * en formato largo: quien analice el fichero agrupa por `respuesta_n`.
     *
     * @return array<int, string>
     */
    private function valuesFor(Question $question, $answer, bool $includeOpenText): array
    {
        if ($question->isFreeText()) {
            if (! $includeOpenText) {
                return [];
            }

            $text = (string) ($answer->text_answer ?? '');

            // El texto llega CRUDO de la base de datos y se neutraliza en
            // CsvInjectionSanitizer::row(). Ese es el unico punto donde se
            // toca, y es el correcto.
            return $text === '' ? [] : [$text];
        }

        return $answer->selections
            ->map(fn ($selection) => (string) ($selection->option->label ?? ''))
            ->filter(fn (string $label) => $label !== '')
            ->values()
            ->all();
    }

    /**
     * Resumen agregado con las dos bases de D-06.
     *
     * Los porcentajes salen de StatsService, no se recalculan aqui. Si el CSV
     * y el dashboard calcularan por separado, acabarian discrepando, y una
     * discrepancia entre la pantalla y el fichero descarada la confianza en
     * ambos. D-07: una sola fuente para estos numeros.
     *
     * @return Generator<int, string>
     */
    private function summarySection(Survey $survey): Generator
    {
        yield $this->sanitizer->row(self::SUMMARY_HEADERS);

        $questions = $survey->questions()->orderBy('position')->get()->keyBy('id');

        foreach ($this->stats->questionStats($survey) as $questionStats) {
            /** @var QuestionStats $questionStats */
            $question = $questions->get($questionStats->questionId);
            $position = $question?->position ?? 0;

            if (! $questionStats->isChoiceBased()) {
                yield $this->sanitizer->row([
                    $position,
                    $questionStats->prompt,
                    $questionStats->typeLabel(),
                    '(texto libre)',
                    $questionStats->textAnswersCount,
                    '',
                    '',
                    $questionStats->answeredCount,
                    '',
                ]);

                continue;
            }

            foreach ($questionStats->options as $option) {
                /** @var OptionStats $option */
                yield $this->sanitizer->row([
                    $position,
                    $questionStats->prompt,
                    $questionStats->typeLabel(),
                    $option->label,
                    $option->count,
                    // D-06 base A: puede superar el 100 % en multiple. Se
                    // exporta tal cual, sin recortar a 100.
                    $this->decimal($option->percentageOfParticipants * 100),
                    // D-06 base B: la suma da exactamente 100.
                    $this->decimal($option->percentageOfSelections * 100),
                    $questionStats->answeredCount,
                    $questionStats->totalSelections,
                ]);
            }
        }
    }

    /**
     * Numero con punto decimal y SIN separador de millares.
     *
     * Un `number_format` con coma decimal produciria `62,5`, que al abrir el
     * CSV en una configuracion regional distinta se parte en dos columnas o se
     * lee como 625. El punto es lo unico portable.
     */
    private function decimal(float $value): string
    {
        return number_format(round($value, 1), 1, '.', '');
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            Question::TYPE_SINGLE_CHOICE => 'seleccion_unica',
            Question::TYPE_MULTIPLE_CHOICE => 'seleccion_multiple',
            Question::TYPE_FREE_TEXT => 'texto_libre',
            default => $type,
        };
    }

    public function filename(Survey $survey): string
    {
        return sprintf(
            'encuestalive-%s-%s.csv',
            $survey->public_id ?? 'borrador',
            now()->format('Ymd-His'),
        );
    }
}
