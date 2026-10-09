@props([
    /* Nombre del estado que se está mostrando: default, hover, focus-visible… */
    'state' => null,
    'note' => null,
    /* Fondo del lienzo: white (superficie) | canvas (ink-50) */
    'surface' => 'white',
])

{{--
    gallery-demo — celda que enmarca UN componente en UN estado.

    El rótulo del estado va SIEMPRE visible: una galería en la que hay que
    adivinar qué estado se está viendo no sirve como banco de pruebas.
--}}

<div {{ $attributes->class('flex min-w-0 flex-col gap-2') }}>
    @if ($state)
        <p class="font-mono text-xs font-semibold text-ink-600">{{ $state }}</p>
    @endif

    <div class="flex flex-wrap items-start gap-3 rounded-lg border border-ink-200 p-4 {{ $surface === 'canvas' ? 'bg-surface-canvas' : 'bg-white' }}">
        {{ $slot }}
    </div>

    @if ($note)
        <p class="max-w-prose text-xs text-ink-500">{{ $note }}</p>
    @endif
</div>
