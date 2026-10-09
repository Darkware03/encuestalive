@php
    /*
     * P10/P11 · Dashboard de resultados en vivo y feed de texto libre.
     *
     * ============ EL SONDEO ES EL RESPALDO OBLIGATORIO (D-02) ============
     * `wire:poll` se pinta SIEMPRE que la conexion no sea `connected`. No es
     * una opcion de configuracion: es lo que garantiza CA-08 si Reverb no
     * arranca en el entorno de destino.
     *
     *   connected    -> sin sondeo, manda el WebSocket
     *   reconnecting -> cada 10 s, para que no se congele durante el corte
     *   degraded     -> cada 5 s, permanente
     *
     * El valor inicial del componente es `degraded`, a proposito: hasta que
     * el JS de Echo confirme que hay socket, el dashboard se comporta como si
     * no lo hubiera. Asumir lo contrario dejaria la pantalla congelada si
     * Reverb no estuviera levantado.
     * =====================================================================
     *
     * TODOS los numeros de esta vista salen de `$stats`, que LiveDashboard
     * obtiene de StatsService leyendo la base de datos en cada render. Aqui no
     * se calcula ni un porcentaje (D-07).
     */
@endphp

<div
    @if ($pollInterval) wire:poll.{{ $pollInterval }}="refreshStats" @endif
    data-dashboard
    data-public-id="{{ $survey->public_id }}"
>
    <x-slot:title>{{ $survey->title }}</x-slot:title>

    {{-- ============================== BANDA DE ESTADO DE CONEXION (18.a) --}}
    {{-- EMPUJA el contenido, no se superpone: taparia datos del dashboard. --}}
    @if ($connectionState !== 'connected')
        <div class="-mx-4 mb-6 md:-mx-6 lg:-mx-8">
            <x-alert :tone="$connectionState === 'reconnecting' ? 'warning' : 'info'">
                @if ($connectionState === 'reconnecting')
                    Reconectando con el servidor de tiempo real…
                    Mientras tanto, los datos se actualizan cada {{ $pollInterval }}.
                @else
                    {{-- Texto HONESTO: no se promete tiempo real si no lo hay. --}}
                    Actualizacion periodica cada {{ $pollInterval }} (tiempo real no disponible).
                @endif
            </x-alert>
        </div>
    @endif

    {{-- Fallo de coherencia: si alguna invariante de PRD §8.7 se rompe, la
         pantalla lo DICE en lugar de pintar numeros que no cuadran (CA-10). --}}
    @if (count($violations))
        <div class="mb-6">
            <x-alert tone="danger" assertive title="Incoherencia en los contadores">
                {{ implode(' · ', $violations) }}
            </x-alert>
        </div>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-3">
            <x-status-badge :status="$stats->status" />

            @if ($stats->fromSnapshot)
                <x-badge tone="neutral">Cifras finales congeladas al cerrar</x-badge>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-4">
            <x-connection-indicator
                :state="$this->indicatorState()"
                :detail="$this->indicatorDetail()"
            />

            {{-- "Actualizado hh:mm:ss" SIEMPRE visible: es la unica afirmacion
                 que el sistema puede sostener con certeza (§8.2). --}}
            <span class="text-sm text-ink-600 tabular">
                Actualizado {{ $lastUpdatedAt }}
            </span>
        </div>
    </div>

    {{-- ========================================================= KPI (P10) --}}
    <div class="mt-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        {{-- "sesiones", nunca "personas": el sistema mide sesiones y no puede
             afirmar conocer personas fisicas (API.md §5.2). --}}
        <x-stat
            label="Sesiones conectadas"
            :value="$stats->connectedSessions"
            note="ahora"
            :stale="$connectionState !== 'connected'"
            :staleNote="$connectionState !== 'connected' ? 'ultimo valor conocido' : null"
        />

        <x-stat
            label="Respuestas completadas"
            :value="$stats->completedResponses"
            note="acumulado"
        />

        <x-stat
            label="Pendientes"
            :value="$stats->pendingSessions"
            note="conectadas sin responder"
        />

        <x-stat
            label="Tasa de respuesta"
            :value="$stats->responseRatePercent() !== null
                ? str_replace('.', ',', (string) $stats->responseRatePercent()) . ' %'
                : '—'"
            :note="'completadas ÷ ' . $stats->sessionsStarted . ' sesiones iniciadas'"
        />
    </div>

    {{-- Descomposicion de PRD §8.4, que el dashboard debe poder cuadrar. --}}
    <p class="mt-3 text-xs text-ink-500">
        {{ $stats->connectedSessions }} conectadas =
        {{ $stats->pendingSessions }} pendientes +
        {{ $stats->connectedCompleted }} ya respondieron ·
        {{ $stats->participatedSessions }} participaron de
        {{ $stats->sessionsStarted }} sesiones iniciadas
    </p>

    {{-- ===================================================== RESULTADOS --}}
    <div class="mt-8 flex flex-col gap-6">
        @forelse ($stats->questions as $q)
            <x-card wire:key="qs-{{ $q->questionId }}" headingLevel="h2">
                <h2 class="text-xl font-semibold text-ink-900" data-user-text>
                    {{ $q->prompt }}
                </h2>

                <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-ink-600">
                    <x-badge tone="brand">
                        {{ [
                            'single_choice' => 'Seleccion unica',
                            'multiple_choice' => 'Seleccion multiple',
                            'free_text' => 'Texto libre',
                        ][$q->type] ?? $q->type }}
                    </x-badge>
                    <span>{{ $q->answeredCount }} respuestas</span>
                    @if ($q->skippedCount > 0)
                        <span>· {{ $q->skippedCount }} sin responder</span>
                    @endif
                </p>

                @if ($q->isChoiceBased())
                    {{--
                        D-06, OBLIGATORIO: el denominador se declara SIEMPRE en
                        pantalla y no en un tooltip. En seleccion multiple la
                        suma puede superar el 100 %, y quien lee el grafico
                        tiene derecho a saber por que antes de sacar
                        conclusiones.
                    --}}
                    <p class="mt-3 rounded-md bg-ink-50 px-3 py-2 text-xs text-ink-700">
                        {{ $q->denominatorLabel() }}
                    </p>

                    @if ($q->answeredCount > 0)
                        <ul class="mt-4 flex flex-col gap-4">
                            @foreach ($q->options as $i => $option)
                                <x-result-bar
                                    wire:key="o-{{ $option->optionId }}"
                                    :label="$option->label"
                                    :count="$option->count"
                                    :percent="$option->percentFor($q->basis())"
                                    :series="($i % 6) + 1"
                                />
                            @endforeach
                        </ul>
                    @else
                        <div class="mt-4">
                            <x-empty-state variant="awaiting-responses" />
                        </div>
                    @endif
                @else
                    <p class="mt-3 text-sm text-ink-600">
                        {{ $q->textAnswersCount }} respuestas con texto.
                        Se muestran abajo, en el feed.
                    </p>
                @endif
            </x-card>
        @empty
            <x-empty-state variant="awaiting-responses" />
        @endforelse
    </div>

    {{-- ================================================= TEXTO LIBRE (P11) --}}
    @php
        $hasFreeText = collect($stats->questions)->contains(fn ($q) => $q->type === 'free_text');
    @endphp

    @if ($hasFreeText)
        <div class="mt-8">
            <x-card headingLevel="h2">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-xl font-semibold text-ink-900">Respuestas de texto libre</h2>

                    {{-- brief §4.5: poder ocultarlo reduce la exposicion de
                         datos personales que alguien escriba por accidente,
                         sobre todo con el dashboard proyectado ante publico. --}}
                    <x-button variant="outline" size="sm" wire:click="toggleOpenTextVisibility">
                        {{ $showOpenText ? 'Ocultar textos' : 'Mostrar textos' }}
                    </x-button>
                </div>

                @if (! $showOpenText)
                    <p class="mt-4 text-sm text-ink-600">
                        Textos ocultos. Util si la pantalla esta proyectada.
                    </p>
                @elseif (count($openText) === 0)
                    <div class="mt-4"><x-empty-state variant="no-text-answers" /></div>
                @else
                    <ul class="mt-4 flex flex-col gap-3">
                        @foreach ($openText as $i => $entry)
                            <li
                                wire:key="t-{{ $i }}"
                                class="rounded-md border border-ink-200 bg-white p-4"
                            >
                                {{--
                                    CA-06: `{{ }}` escapa por defecto. Esta
                                    PROHIBIDO `{!! !!}` sobre contenido de
                                    participantes. Un <script> escrito aqui se
                                    muestra como texto, no se ejecuta.

                                    El texto se guardo EN CRUDO en la base de
                                    datos; el escapado ocurre aqui, que es el
                                    punto donde se conoce el contexto de salida.
                                --}}
                                <p class="whitespace-pre-wrap text-base text-ink-900" data-user-text>{{ $entry['text'] }}</p>

                                <p class="mt-2 text-xs text-ink-500">
                                    {{ $entry['received_at']->format('H:i:s') }}
                                    {{-- Sin identificador de sesion: no se puede
                                         ligar este texto con el resto de
                                         respuestas de la misma persona. --}}
                                </p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>
        </div>
    @endif

    {{-- ======================================================= ACCIONES --}}
    <div class="mt-8 flex flex-wrap gap-2">
        <x-button
            variant="outline"
            icon="document-text"
            :href="route('admin.surveys.export', $survey)"
        >Exportar CSV</x-button>

        <x-button variant="ghost" :href="route('admin.surveys.edit', $survey)">
            Volver al editor
        </x-button>
    </div>

    <p class="mt-3 text-xs text-ink-500">
        El CSV no incluye direcciones IP ni identificadores tecnicos de sesion,
        y neutraliza las formulas de hoja de calculo.
    </p>
</div>
