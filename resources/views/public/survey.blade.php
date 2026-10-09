@php
    /*
     * P8 · Pantalla de participacion (UX_UI_SPEC pantallas 8, 9, 14).
     *
     * ============== FORMULARIO HTML CLASICO, NO LIVEWIRE ==============
     * Decision deliberada, y es una DESVIACION CONSCIENTE de API.md §4.4,
     * que preveia un componente Livewire `Public\SurveyForm`.
     *
     * Motivo: ARCHITECTURE.md §8.1 y wireframes-states 16.d exigen que el
     * participante pueda LEER Y MARCAR sin esperar a JavaScript. Un
     * `wire:submit` no degrada: sin JS no envia nada. Y el escenario real de
     * este producto es un movil con 4G saturada en una sala llena, que es
     * precisamente donde el bundle puede no llegar.
     *
     * Con un <form> nativo el envio funciona siempre, y el JS que si carga
     * anade encima los reintentos idempotentes y el borrador en localStorage.
     * El servidor usa EL MISMO ParticipationService en ambos caminos, asi que
     * no hay dos validaciones que puedan divergir.
     * ==================================================================
     */
    $questionCount = $survey->questions->count();
@endphp

<x-layouts.participant
    :title="$survey->title"
    :connection="$acceptsResponses ? 'online' : 'stale'"
>
    {{-- ========================================= YA RESPONDISTE (P9) --}}
    @if ($alreadyAnswered)
        <div class="flex flex-col items-center text-center">
            <span class="text-success-700"><x-icon name="check-circle" :size="48" /></span>

            <h1 class="mt-4 text-2xl font-bold text-ink-900">Respuesta enviada</h1>
            <p class="mt-2 text-base text-ink-700">
                Gracias por participar en <strong>{{ $survey->title }}</strong>.
            </p>

            @if (session('receipt_id'))
                <p class="mt-4 text-sm text-ink-600">
                    Tu recibo:
                    <code class="font-mono font-semibold text-ink-900">{{ session('receipt_id') }}</code>
                </p>
            @endif

            {{-- RN-04 / D-05 PA-05: no hay edicion de respuesta. Se dice
                 abiertamente en lugar de dejar un boton que fallaria. --}}
            <p class="mt-6 max-w-[40ch] text-sm text-ink-600">
                Las respuestas son anonimas y no se pueden modificar una vez enviadas.
                Puedes cerrar esta pagina.
            </p>
        </div>

    {{-- ====================================== CERRADA O PAUSADA (P14) --}}
    @elseif (! $acceptsResponses)
        <div class="flex flex-col items-center text-center">
            <span class="text-ink-500"><x-icon name="x-circle" :size="48" /></span>

            <h1 class="mt-4 text-2xl font-bold text-ink-900">
                @if ($survey->status === \App\Models\Survey::STATUS_PAUSED)
                    Encuesta pausada
                @else
                    Encuesta cerrada
                @endif
            </h1>

            <div class="mt-3">
                <x-status-badge :status="$survey->status" />
            </div>

            <p class="mt-4 max-w-[40ch] text-base text-ink-700">
                @if ($survey->status === \App\Models\Survey::STATUS_PAUSED)
                    La persona organizadora la ha pausado temporalmente.
                    Vuelve a intentarlo en unos minutos.
                @else
                    Esta encuesta ya no admite respuestas. Gracias por tu interes.
                @endif
            </p>
        </div>

    {{-- ============================================== FORMULARIO (P8) --}}
    @else
        <h1 class="text-2xl font-bold text-ink-900" data-user-text>{{ $survey->title }}</h1>

        @if ($survey->description)
            <p class="mt-2 text-base text-ink-700" data-user-text>{{ $survey->description }}</p>
        @endif

        @if ($survey->instructions)
            <div class="mt-4">
                <x-alert tone="info">{{ $survey->instructions }}</x-alert>
            </div>
        @endif

        <p class="mt-4 flex flex-wrap items-center gap-2">
            <x-badge tone="success" icon="lock-closed">Anonima</x-badge>
            <span class="text-xs text-ink-500">No se pide cuenta, nombre ni correo.</span>
        </p>

        {{-- Resumen de errores de servidor. El foco salta aqui al aparecer. --}}
        @if ($errors->any())
            <div class="mt-6">
                <x-error-summary
                    :errors="collect($errors->getMessages())->map(fn ($messages, $key) => [
                        'label' => 'Pregunta',
                        'message' => $messages[0],
                        'anchor' => 'p-' . $key,
                    ])->values()->all()"
                />
            </div>
        @endif

        <form
            id="survey-form"
            method="POST"
            action="{{ route('public.survey.submit', ['publicId' => $survey->public_id]) }}"
            class="mt-8 flex flex-col gap-8"
            data-survey-form
            data-public-id="{{ $survey->public_id }}"
            data-heartbeat-seconds="{{ $heartbeatSeconds }}"
            data-heartbeat-url="{{ route('public.survey.heartbeat', ['publicId' => $survey->public_id]) }}"
        >
            @csrf

            @foreach ($survey->questions as $index => $question)
                @php
                    $name = 'q[' . $question->id . ']';
                    $errorKey = 'answers.' . $index;
                    $error = $errors->first($errorKey . '.option_ids')
                        ?: $errors->first($errorKey . '.text')
                        ?: $errors->first('answers.question_' . $question->id);
                    $old = old('q.' . $question->id);
                @endphp

                <div id="p-{{ $errorKey }}">
                    {{-- ------------------------------------ SELECCION UNICA --}}
                    @if ($question->type === \App\Models\Question::TYPE_SINGLE_CHOICE)
                        <x-choice-group
                            :legend="($index + 1) . '. ' . $question->prompt"
                            :required="$question->is_required"
                            :error="$error"
                            :hint="$question->is_required ? null : 'Opcional'"
                        >
                            <div class="flex flex-col gap-3">
                                @foreach ($question->options as $option)
                                    <x-choice-option
                                        type="radio"
                                        :name="$name"
                                        :value="$option->id"
                                        :id="'o-' . $option->id"
                                        :checked="(string) $old === (string) $option->id"
                                    >{{ $option->label }}</x-choice-option>
                                @endforeach
                            </div>
                        </x-choice-group>

                    {{-- ---------------------------------- SELECCION MULTIPLE --}}
                    @elseif ($question->type === \App\Models\Question::TYPE_MULTIPLE_CHOICE)
                        @php
                            $min = (int) ($question->min_selections ?? 1);
                            $max = (int) ($question->max_selections ?? $question->options->count());
                            $chosen = is_array($old) ? array_map('strval', $old) : [];
                        @endphp

                        <x-choice-group
                            :legend="($index + 1) . '. ' . $question->prompt"
                            :required="$question->is_required"
                            :error="$error"
                            :min="$min"
                            :max="$max"
                            :count="count($chosen)"
                            :hint="$min === $max
                                ? 'Elige exactamente ' . $min
                                : 'Elige entre ' . $min . ' y ' . $max . ' opciones'"
                        >
                            <div class="flex flex-col gap-3">
                                @foreach ($question->options as $option)
                                    <x-choice-option
                                        type="checkbox"
                                        :name="$name . '[]'"
                                        :value="$option->id"
                                        :id="'o-' . $option->id"
                                        :checked="in_array((string) $option->id, $chosen, true)"
                                    >{{ $option->label }}</x-choice-option>
                                @endforeach
                            </div>
                        </x-choice-group>

                    {{-- ----------------------------------------- TEXTO LIBRE --}}
                    @else
                        <x-textarea
                            :id="'q-' . $question->id"
                            :name="$name"
                            :label="($index + 1) . '. ' . $question->prompt"
                            :required="$question->is_required"
                            :error="$error"
                            :value="is_string($old) ? $old : ''"
                            :maxlength="(int) ($question->max_length ?? 1000)"
                            :hint="$question->is_required ? null : 'Opcional'"
                            rows="4"
                        />
                    @endif
                </div>
            @endforeach
        </form>

        {{--
            Accion primaria FIJA ABAJO, ancho completo, 48px (§10).

            Vive FUERA del <form> porque el slot `actionBar` del layout se
            renderiza en la barra inferior pegajosa, que esta fuera del
            elemento de formulario. Se enlaza con `form="survey-form"`, que es
            HTML5 estandar y permite que un boton externo envie el formulario
            sin una sola linea de JavaScript. Sin este atributo, el boton
            quedaria mudo justo en el escenario sin JS que esta pantalla tiene
            obligacion de soportar.
        --}}
        <x-slot:actionBar>
            <x-button
                type="submit"
                form="survey-form"
                variant="primary"
                size="lg"
                block
                loadingLabel="Enviando…"
                data-submit-button
            >Enviar respuestas</x-button>

            <p class="mt-2 pb-3 text-center text-xs text-ink-500">
                {{ $questionCount }}
                {{ $questionCount === 1 ? 'pregunta' : 'preguntas' }} ·
                Tu respuesta es anonima
            </p>
        </x-slot:actionBar>
    @endif
</x-layouts.participant>
