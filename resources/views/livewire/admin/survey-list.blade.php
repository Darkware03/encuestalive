@php
    /*
     * P2/P3/P13 · Panel, listado e historial (UX_UI_SPEC §3.2).
     *
     * Las acciones de estado se pintan a partir de `$machine->availableActions()`,
     * que es LA MISMA maquina de estados que el servidor aplica al ejecutarlas.
     * Asi la interfaz no puede ofrecer un boton que el servidor vaya a rechazar,
     * ni esconder uno que si seria valido.
     *
     * Y al reves: que el boton no se pinte NO es el control de acceso. El
     * control esta en SurveyPolicy + SurveyStateMachine dentro del componente.
     */
@endphp

<div>
    <x-slot:title>{{ $archived ? 'Historial' : 'Panel' }}</x-slot:title>

    {{-- ===================================================== KPI (P2) --}}
    @unless ($archived)
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <x-stat label="Encuestas activas" :value="$activeCount" note="ahora" />

            {{-- "sesiones conectadas", NUNCA "personas conectadas": el sistema
                 mide sesiones y no puede afirmar conocer personas fisicas
                 (API.md §5.2, honestidad obligatoria). --}}
            <x-stat
                label="Sesiones conectadas"
                :value="$connectedTotal"
                note="en encuestas activas"
            />

            <x-stat
                label="Respuestas completadas"
                :value="$completedTotal"
                note="acumulado"
            />
        </div>
    @endunless

    {{-- ================================================ AVISOS Y ERRORES --}}
    @if ($flash)
        <div class="mt-6" wire:key="flash">
            <x-alert tone="success">{{ $flash }}</x-alert>
        </div>
    @endif

    @error('status')
        <div class="mt-6">
            <x-alert tone="warning" assertive>{{ $message }}</x-alert>
        </div>
    @enderror

    {{-- ========================================================= FILTROS --}}
    <div class="mt-8 flex flex-wrap items-end justify-between gap-4">
        <div class="flex flex-wrap items-end gap-3">
            <x-input
                name="buscar"
                label="Buscar"
                placeholder="Titulo de la encuesta"
                leadingIcon="magnifying-glass"
                wire:model.live.debounce.400ms="search"
                class="w-full sm:w-64"
            />

            <x-select
                name="estado"
                label="Estado"
                placeholder="Todos"
                :options="collect($statuses)->mapWithKeys(fn ($s) => [$s => ucfirst($s)])->all()"
                :selected="$statusFilter"
                wire:model.live="statusFilter"
                class="w-full sm:w-48"
            />
        </div>

        @unless ($archived)
            <x-button variant="primary" icon="plus" wire:click="createSurvey">
                Crear encuesta
            </x-button>
        @endunless
    </div>

    {{-- ========================================================= LISTADO --}}
    <div class="mt-6">
        @if ($surveys->isEmpty())
            <x-empty-state :variant="$archived ? 'no-results' : 'no-surveys'">
                @unless ($archived)
                    <x-button variant="primary" icon="plus" wire:click="createSurvey">
                        Crear encuesta
                    </x-button>
                @endunless
            </x-empty-state>
        @else
            <x-table
                caption="Encuestas"
                :columns="[
                    ['label' => 'Encuesta'],
                    ['label' => 'Estado'],
                    ['label' => 'Respuestas', 'numeric' => true],
                    ['label' => 'Acciones'],
                ]"
            >
                @foreach ($surveys as $survey)
                    <x-table-row wire:key="s-{{ $survey->id }}">
                        <x-table-cell header>
                            <a
                                href="{{ route('admin.surveys.edit', $survey) }}"
                                class="rounded-sm font-semibold text-brand-700 underline underline-offset-2
                                       hover:text-brand-800 focus-visible:outline-2
                                       focus-visible:outline-offset-2 focus-visible:outline-brand-700"
                                data-user-text
                            >{{ $survey->title }}</a>

                            @if ($survey->version_number > 1)
                                <span class="ml-2"><x-badge tone="neutral">v{{ $survey->version_number }}</x-badge></span>
                            @endif
                        </x-table-cell>

                        <x-table-cell>
                            <x-status-badge :status="$survey->status" />
                        </x-table-cell>

                        <x-table-cell numeric>{{ $survey->responses_count }}</x-table-cell>

                        <x-table-cell>
                            @include('livewire.admin.partials.survey-actions', [
                                'survey' => $survey,
                                'machine' => $machine,
                            ])
                        </x-table-cell>
                    </x-table-row>
                @endforeach

                {{-- < md: la tabla se transforma en tarjetas. Nunca scroll
                     lateral (regla dura de x-table). --}}
                <x-slot:cards>
                    <ul class="flex flex-col gap-4">
                        @foreach ($surveys as $survey)
                            <li wire:key="c-{{ $survey->id }}">
                                <x-card>
                                    <div class="flex items-start justify-between gap-3">
                                        <a
                                            href="{{ route('admin.surveys.edit', $survey) }}"
                                            class="min-w-0 flex-1 rounded-sm font-semibold text-brand-700 underline underline-offset-2"
                                            data-user-text
                                        >{{ $survey->title }}</a>
                                        <x-status-badge :status="$survey->status" />
                                    </div>

                                    <p class="mt-2 text-sm text-ink-600">
                                        {{ $survey->responses_count }} respuestas
                                    </p>

                                    <div class="mt-4">
                                        @include('livewire.admin.partials.survey-actions', [
                                            'survey' => $survey,
                                            'machine' => $machine,
                                        ])
                                    </div>
                                </x-card>
                            </li>
                        @endforeach
                    </ul>
                </x-slot:cards>
            </x-table>

            <div class="mt-6">{{ $surveys->links() }}</div>
        @endif
    </div>
</div>
