<?php

namespace Tests\Support;

use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Survey;
use App\Models\User;

/**
 * Constructor de encuestas para las pruebas.
 *
 * Evita que cada prueba repita veinte lineas de montaje y, sobre todo, evita
 * que cada una monte una encuesta LIGERAMENTE distinta: cuando el fixture
 * varia sin querer, una prueba puede pasar por la razon equivocada.
 */
trait BuildsSurveys
{
    /**
     * Tarro de cookies del participante.
     *
     * Laravel NO propaga las cookies de una respuesta a la siguiente peticion
     * en las pruebas: comprobado con una sonda, un GET y un POST seguidos
     * crean DOS sesiones distintas. Sin esto, cualquier prueba de
     * anti-duplicado estaria midiendo dos participantes en lugar de uno, y
     * pasaria o fallaria por la razon equivocada.
     *
     * Las cookies se reenvian TAL COMO LLEGAN, es decir, cifradas por
     * EncryptCookies. Reenviar el token en claro NO funciona (tambien
     * comprobado): el framework intenta descifrarlo, falla y emite una sesion
     * nueva. Esto imita a un navegador real, que tampoco entiende el
     * contenido de la cookie y se limita a devolverla.
     *
     * @var array<string, string>
     */
    protected array $participantJar = [];

    protected function owner(): User
    {
        return User::factory()->create();
    }

    /**
     * Empieza una sesion de participante COMPLETAMENTE nueva.
     *
     * Imprescindible para simular varias personas en una misma prueba, y
     * mucho menos obvio de lo que parece: `withUnencryptedCookies()` hace
     * `array_merge` sobre `$this->unencryptedCookies`
     * (MakesHttpRequests.php:266), asi que vaciar solo el tarro propio NO
     * borra las cookies que el framework ya acumulo. El segundo participante
     * seguiria enviando la cookie del primero, reutilizaria su sesion, su
     * envio chocaria con el anti-duplicado y la prueba mediria UNA persona
     * creyendo que mide tres.
     *
     * Se limpian las tres estructuras, no solo la propia.
     */
    protected function newParticipant(): void
    {
        $this->participantJar = [];
        $this->unencryptedCookies = [];
        $this->defaultCookies = [];
    }

    /** Guarda las cookies de una respuesta para la siguiente peticion. */
    protected function captureCookies($response): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            $value = (string) $cookie->getValue();

            // Una cookie vaciada es un borrado: no se debe reenviar.
            if ($value === '') {
                unset($this->participantJar[$cookie->getName()]);

                continue;
            }

            $this->participantJar[$cookie->getName()] = $value;
        }
    }

    /** Peticion que lleva las cookies acumuladas, como haria un navegador. */
    protected function asParticipant()
    {
        return $this->withUnencryptedCookies($this->participantJar);
    }

    /** Abre la encuesta y memoriza la sesion anonima emitida. */
    protected function openSurvey(Survey $survey)
    {
        $response = $this->asParticipant()->get('/s/' . $survey->public_id);
        $this->captureCookies($response);

        return $response;
    }

    /**
     * Envia respuestas conservando la MISMA sesion anonima.
     *
     * Se usa `post()` con cabecera `Accept` en lugar de `postJson()` por un
     * motivo medido, no por estilo: `postJson()` NO propaga las cookies de
     * `withUnencryptedCookies()`. Comprobado con una sonda que repitio el
     * mismo flujo con tres variantes; solo `postJson` perdia la cookie y
     * creaba una segunda sesion.
     *
     * Si esto no se corrigiera, las pruebas de anti-duplicado estarian
     * midiendo DOS participantes distintos y pasarian por la razon
     * equivocada, que es peor que fallar.
     */
    protected function submitAs(Survey $survey, array $answers, ?string $uuid = null)
    {
        $response = $this->asParticipant()->post(
            '/s/' . $survey->public_id . '/responses',
            [
                'submission_uuid' => $uuid ?? (string) \Illuminate\Support\Str::uuid(),
                'answers' => $answers,
            ],
            ['Accept' => 'application/json'],
        );

        $this->captureCookies($response);

        return $response;
    }

    /**
     * Encuesta publicada con los TRES tipos de pregunta.
     *
     * @return array{survey: Survey, single: Question, multiple: Question, text: Question}
     */
    protected function publishedSurveyWithAllTypes(?User $owner = null, array $overrides = []): array
    {
        $owner ??= $this->owner();

        $survey = Survey::factory()->published()->create(array_merge([
            'created_by_user_id' => $owner->id,
            'title' => 'Evaluacion de la jornada',
        ], $overrides));

        $single = Question::factory()->create([
            'survey_id' => $survey->id,
            'type' => Question::TYPE_SINGLE_CHOICE,
            'prompt' => 'Como valoras la jornada?',
            'is_required' => true,
            'position' => 1,
        ]);
        $this->addOptions($single, ['Excelente', 'Buena', 'Mejorable']);

        // min 2 / max 3: permite comprobar los DOS limites de RN-01.5.
        $multiple = Question::factory()->create([
            'survey_id' => $survey->id,
            'type' => Question::TYPE_MULTIPLE_CHOICE,
            'prompt' => 'Que temas te interesaron?',
            'is_required' => true,
            'position' => 2,
            'min_selections' => 2,
            'max_selections' => 3,
        ]);
        $this->addOptions($multiple, ['Metodologia', 'Herramientas', 'Casos', 'Normativa']);

        $text = Question::factory()->create([
            'survey_id' => $survey->id,
            'type' => Question::TYPE_FREE_TEXT,
            'prompt' => 'Algun comentario?',
            'is_required' => false,
            'position' => 3,
            'min_length' => 0,
            'max_length' => 200,
        ]);

        return compact('survey', 'single', 'multiple', 'text');
    }

    /**
     * @param  array<int, string>  $labels
     * @return array<int, QuestionOption>
     */
    protected function addOptions(Question $question, array $labels): array
    {
        $created = [];
        $position = 1;

        foreach ($labels as $label) {
            $created[] = QuestionOption::create([
                'question_id' => $question->id,
                'label' => $label,
                'position' => $position++,
            ]);
        }

        return $created;
    }

    /** Cuerpo valido de envio para la encuesta de arriba. */
    protected function validAnswers(array $built, ?string $text = null): array
    {
        return [
            [
                'question_id' => $built['single']->id,
                'option_ids' => [$built['single']->options()->first()->id],
            ],
            [
                'question_id' => $built['multiple']->id,
                'option_ids' => $built['multiple']->options()->limit(2)->pluck('id')->all(),
            ],
            [
                'question_id' => $built['text']->id,
                'text' => $text ?? 'Todo bien.',
            ],
        ];
    }
}
