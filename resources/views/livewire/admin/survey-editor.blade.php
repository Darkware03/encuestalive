@php
    /*
     * P4/P5/P6/P7 · Editor de encuesta, preguntas, publicacion y QR.
     *
     * La pantalla refleja la POLITICA DE VERSIONADO del brief §4.2:
     * `$editable` es falso en cuanto la encuesta se publica, y entonces el
     * bloque de preguntas pasa a solo lectura con una explicacion y un camino
     * de salida (duplicar como nueva version), no con un muro.
     */
@endphp

<div>
    <x-slot:title>{{ $survey->title }}</x-slot:title>

    {{-- ================================================ AVISOS Y ERRORES --}}
    @if ($flash)
        <div class="mb-6"><x-alert tone="success">{{ $flash }}</x-alert></div>
    @endif

    @error('status')
        <div class="mb-6"><x-alert tone="warning" assertive>{{ $message }}</x-alert></div>
    @enderror

    @error('structure')
        <div class="mb-6"><x-alert tone="warning" assertive>{{ $message }}</x-alert></div>
    @enderror

    <div class="flex flex-wrap items-center gap-3">
        <x-status-badge :status="$survey->status" />

        @if ($survey->version_number > 1)
            <x-badge tone="neutral">Version {{ $survey->version_number }}</x-badge>
        @endif

        @unless ($editable)
            <x-badge tone="info" icon="lock-closed">Estructura congelada</x-badge>
        @endunless
    </div>

    {{-- ======================================================= CABECERA --}}
    <div class="mt-6">
        <x-card title="Datos de la encuesta" headingLevel="h2">
            <div class="flex flex-col gap-5">
                <x-input
                    name="title"
                    label="Titulo"
                    required
                    wire:model="title"
                    :error="$errors->first('title')"
                />

                <x-textarea
                    name="description"
                    label="Descripcion"
                    :maxlength="2000"
                    rows="3"
                    wire:model="description"
                    :value="$description"
                    :error="$errors->first('description')"
                />

                <x-textarea
                    name="instructions"
                    label="Instrucciones para quien responde"
                    :maxlength="2000"
                    rows="3"
                    wire:model="instructions"
                    :value="$instructions"
                    :error="$errors->first('instructions')"
                />

                @if ($editable)
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-input
                            name="startsAt"
                            type="datetime-local"
                            label="Inicio (opcional)"
                            wire:model="startsAt"
                            :error="$errors->first('startsAt')"
                        />
                        <x-input
                            name="endsAt"
                            type="datetime-local"
                            label="Fin (opcional)"
                            wire:model="endsAt"
                            :error="$errors->first('endsAt')"
                        />
                    </div>
                @endif

                <div>
                    <x-button variant="primary" wire:click="save" wire:loading.attr="disabled">
                        Guardar cambios
                    </x-button>
                </div>
            </div>
        </x-card>
    </div>

    {{-- ======================================================= PREGUNTAS --}}
    <div class="mt-6">
        <x-card headingLevel="h2">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-xl font-semibold text-ink-900">
                    Preguntas ({{ $survey->questions->count() }})
                </h2>

                @if ($editable)
                    <div class="flex flex-wrap gap-2">
                        <x-button variant="outline" size="sm" icon="plus"
                                  wire:click="addQuestion('single_choice')">
                            Seleccion unica
                        </x-button>
                        <x-button variant="outline" size="sm" icon="plus"
                                  wire:click="addQuestion('multiple_choice')">
                            Seleccion multiple
                        </x-button>
                        <x-button variant="outline" size="sm" icon="plus"
                                  wire:click="addQuestion('free_text')">
                            Texto libre
                        </x-button>
                    </div>
                @endif
            </div>

            @unless ($editable)
                {{-- No es un muro: explica POR QUE y ofrece la salida. --}}
                <div class="mt-4">
                    <x-alert tone="info" title="La estructura no se puede cambiar">
                        Esta encuesta ya se publico. Cambiar sus preguntas ahora dejaria
                        respuestas dadas sobre preguntas distintas mezcladas en el mismo
                        grafico. Para modificarlas, duplicala como nueva version.
                        <x-slot:action>
                            <x-button variant="outline" size="sm" wire:click="duplicate">
                                Duplicar como nueva version
                            </x-button>
                        </x-slot:action>
                    </x-alert>
                </div>
            @endunless

            <div class="mt-6 flex flex-col gap-5">
                @forelse ($survey->questions as $index => $question)
                    <div
                        wire:key="q-{{ $question->id }}"
                        class="rounded-lg border border-ink-200 p-4"
                    >
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-semibold text-ink-600 tabular">
                                    {{ $index + 1 }}
                                </span>
                                <x-badge tone="brand">
                                    {{ [
                                        'single_choice' => 'Seleccion unica',
                                        'multiple_choice' => 'Seleccion multiple',
                                        'free_text' => 'Texto libre',
                                    ][$question->type] ?? $question->type }}
                                </x-badge>
                                @if ($question->is_required)
                                    <x-badge tone="warning">Obligatoria</x-badge>
                                @endif
                            </div>

                            @if ($editable)
                                <div class="flex items-center gap-1">
                                    <x-button variant="ghost" size="sm" iconOnly icon="arrow-up"
                                              label="Subir" wire:click="moveQuestion({{ $question->id }}, -1)" />
                                    <x-button variant="ghost" size="sm" iconOnly icon="arrow-down"
                                              label="Bajar" wire:click="moveQuestion({{ $question->id }}, 1)" />
                                    <x-button variant="ghost" size="sm" iconOnly icon="x-mark"
                                              label="Eliminar pregunta"
                                              wire:click="removeQuestion({{ $question->id }})"
                                              wire:confirm="¿Eliminar esta pregunta?" />
                                </div>
                            @endif
                        </div>

                        <div class="mt-3">
                            <x-input
                                :name="'prompt-' . $question->id"
                                label="Enunciado"
                                :value="$question->prompt"
                                :disabled="! $editable"
                                wire:change="updatePrompt({{ $question->id }}, $event.target.value)"
                            />
                        </div>

                        {{-- ------------------------------------- opciones --}}
                        @if ($question->type !== 'free_text')
                            <ul class="mt-4 flex flex-col gap-2">
                                @foreach ($question->options as $option)
                                    <li class="flex items-center gap-2" wire:key="o-{{ $option->id }}">
                                        <x-input
                                            :name="'opt-' . $option->id"
                                            :value="$option->label"
                                            :disabled="! $editable"
                                            class="flex-1"
                                            wire:change="updateOption({{ $option->id }}, $event.target.value)"
                                        />
                                        @if ($editable)
                                            <x-button variant="ghost" size="sm" iconOnly icon="minus"
                                                      label="Quitar opcion"
                                                      wire:click="removeOption({{ $option->id }})" />
                                        @endif
                                    </li>
                                @endforeach
                            </ul>

                            @if ($editable)
                                <div class="mt-3">
                                    <x-button variant="ghost" size="sm" icon="plus"
                                              wire:click="addOption({{ $question->id }})">
                                        Anadir opcion
                                    </x-button>
                                </div>
                            @endif

                            {{-- RN-01.5: 1 <= min <= max <= nº de opciones --}}
                            @if ($question->type === 'multiple_choice')
                                <div class="mt-4 grid grid-cols-2 gap-3 sm:max-w-xs">
                                    <x-input
                                        :name="'min-' . $question->id"
                                        type="number"
                                        label="Minimo"
                                        :value="$question->min_selections ?? 1"
                                        :disabled="! $editable"
                                        wire:change="setSelectionLimits({{ $question->id }}, $event.target.value, {{ $question->max_selections ?? $question->options->count() }})"
                                    />
                                    <x-input
                                        :name="'max-' . $question->id"
                                        type="number"
                                        label="Maximo"
                                        :value="$question->max_selections ?? $question->options->count()"
                                        :disabled="! $editable"
                                        wire:change="setSelectionLimits({{ $question->id }}, {{ $question->min_selections ?? 1 }}, $event.target.value)"
                                    />
                                </div>
                                @error('min_selections')
                                    <x-field-error :message="$message" />
                                @enderror
                            @endif
                        @else
                            {{-- RN-01.6: 0 <= min < max <= 2000 --}}
                            <div class="mt-4 grid grid-cols-2 gap-3 sm:max-w-xs">
                                <x-input
                                    :name="'minlen-' . $question->id"
                                    type="number"
                                    label="Longitud minima"
                                    :value="$question->min_length ?? 0"
                                    :disabled="! $editable"
                                    wire:change="setTextLimits({{ $question->id }}, $event.target.value, {{ $question->max_length ?? 1000 }})"
                                />
                                <x-input
                                    :name="'maxlen-' . $question->id"
                                    type="number"
                                    label="Longitud maxima"
                                    :value="$question->max_length ?? 1000"
                                    :disabled="! $editable"
                                    wire:change="setTextLimits({{ $question->id }}, {{ $question->min_length ?? 0 }}, $event.target.value)"
                                />
                            </div>
                            @error('max_length')
                                <x-field-error :message="$message" />
                            @enderror
                        @endif

                        @if ($blockers['question.' . $question->id] ?? false)
                            <div class="mt-3">
                                <x-field-error :message="$blockers['question.' . $question->id]" />
                            </div>
                        @endif
                    </div>
                @empty
                    <x-empty-state
                        variant="no-surveys"
                        title="Esta encuesta no tiene preguntas"
                        description="Anade al menos una para poder publicarla."
                    />
                @endforelse
            </div>
        </x-card>
    </div>

    {{-- ===================================================== PUBLICACION --}}
    <div class="mt-6">
        <x-card title="Publicacion" headingLevel="h2">

            {{-- RN-01: la lista COMPLETA de lo que falta, no el primer fallo. --}}
            @if (count($blockers) && in_array($survey->status, ['draft', 'scheduled'], true))
                <x-error-summary
                    title="Falta esto para poder publicar:"
                    :errors="collect($blockers)->map(fn ($msg, $key) => [
                        'label' => 'Revisar',
                        'message' => $msg,
                        'anchor' => 'q-' . $key,
                    ])->values()->all()"
                />
            @endif

            @if ($publicUrl)
                <div class="mt-4 flex flex-col gap-6 md:flex-row md:items-start">
                    <div class="shrink-0">
                        {{-- El QR codifica exactamente $publicUrl. Nada mas. --}}
                        <img
                            src="{{ $qrDataUri }}"
                            alt="Codigo QR de la encuesta {{ $survey->title }}"
                            width="320"
                            height="320"
                            class="rounded-lg border border-ink-200 bg-white"
                        >
                    </div>

                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-ink-600">Enlace publico</p>
                        <p class="mt-1 break-all font-mono text-sm text-ink-900">{{ $publicUrl }}</p>

                        <div class="mt-4 flex flex-wrap gap-2">
                            <x-button variant="outline" size="sm" icon="qr-code"
                                      :href="route('admin.surveys.qr', ['survey' => $survey, 'format' => 'png'])">
                                Descargar PNG
                            </x-button>
                            <x-button variant="outline" size="sm" icon="qr-code"
                                      :href="route('admin.surveys.qr', ['survey' => $survey, 'format' => 'svg'])">
                                Descargar SVG
                            </x-button>
                            <x-button variant="ghost" size="sm" icon="document-text"
                                      :href="$publicUrl" target="_blank" rel="noopener">
                                Vista previa
                            </x-button>
                        </div>

                        <p class="mt-3 text-xs text-ink-500">
                            El SVG es el formato para imprimir carteles: no pierde nitidez
                            al ampliarlo.
                        </p>
                    </div>
                </div>
            @endif

            <div class="mt-6 flex flex-wrap gap-2">
                @if ($machine->can($survey, 'publish'))
                    <x-button variant="primary" wire:click="publish">Publicar</x-button>
                @endif
                @if ($machine->can($survey, 'pause'))
                    <x-button variant="outline" wire:click="pause">Pausar</x-button>
                @endif
                @if ($machine->can($survey, 'resume'))
                    <x-button variant="outline" wire:click="resume">Reanudar</x-button>
                @endif
                @if ($machine->can($survey, 'close'))
                    <x-button variant="danger" wire:click="close"
                              wire:confirm="Cerrar es irreversible. ¿Continuar?">
                        Cerrar
                    </x-button>
                @endif
                @if ($machine->can($survey, 'archive'))
                    <x-button variant="ghost" wire:click="archive">Archivar</x-button>
                @endif

                <x-button variant="ghost" wire:click="duplicate">Duplicar</x-button>

                @if ($survey->public_id)
                    <x-button variant="ghost" icon="chart-bar"
                              :href="route('admin.surveys.results', $survey)">
                        Ver resultados
                    </x-button>
                @endif
            </div>
        </x-card>
    </div>
</div>
