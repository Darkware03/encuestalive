@php
    /*
     * Acciones disponibles de una encuesta.
     *
     * La lista sale de SurveyStateMachine::availableActions(), la MISMA clase
     * que el servidor consulta al ejecutar la transicion. Una sola definicion
     * del grafo de estados: la interfaz no puede ofrecer algo que el servidor
     * rechazaria, ni ocultar algo que aceptaria.
     */
    $available = $machine->availableActions($survey);
@endphp

<div class="flex flex-wrap items-center gap-2">
    @if ($survey->public_id)
        <x-button
            variant="ghost"
            size="sm"
            icon="chart-bar"
            :href="route('admin.surveys.results', $survey)"
        >Resultados</x-button>
    @endif

    @if (in_array('publish', $available, true))
        <x-button variant="primary" size="sm" wire:click="publish({{ $survey->id }})">
            Publicar
        </x-button>
    @endif

    @if (in_array('pause', $available, true))
        <x-button variant="outline" size="sm" wire:click="pause({{ $survey->id }})">
            Pausar
        </x-button>
    @endif

    @if (in_array('resume', $available, true))
        <x-button variant="outline" size="sm" wire:click="resume({{ $survey->id }})">
            Reanudar
        </x-button>
    @endif

    @if (in_array('close', $available, true))
        {{-- Cerrar es IRREVERSIBLE (D-05 PA-04). Se avisa antes, porque no
             hay vuelta atras posible una vez pulsado. --}}
        <x-button
            variant="danger"
            size="sm"
            wire:click="close({{ $survey->id }})"
            wire:confirm="Cerrar es irreversible: la encuesta dejara de aceptar respuestas para siempre. ¿Continuar?"
        >Cerrar</x-button>
    @endif

    @if (in_array('archive', $available, true))
        <x-button variant="ghost" size="sm" wire:click="archive({{ $survey->id }})">
            Archivar
        </x-button>
    @endif

    {{-- Duplicar como nueva version: la alternativa a la edicion destructiva
         de una encuesta ya publicada (RN-03). --}}
    <x-button variant="ghost" size="sm" icon="document-text" wire:click="duplicate({{ $survey->id }})">
        Duplicar
    </x-button>

    @can('delete', $survey)
        <x-button
            variant="ghost"
            size="sm"
            wire:click="deleteSurvey({{ $survey->id }})"
            wire:confirm="¿Eliminar este borrador? No se puede deshacer."
        >Eliminar</x-button>
    @endcan
</div>
