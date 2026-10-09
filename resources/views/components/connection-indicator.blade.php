@props([
    /* online | reconnecting | offline | stale */
    'state' => 'online',
    /* Texto variable: «act. hace 2 s», «(2/5)», «datos de hace 3 min» */
    'detail' => null,
])

{{--
    connection-indicator — §6.14. Componente CRÍTICO del producto.
    Presente en P2, P7, P8, P10 y P11.

    LAS TRES REGLAS:
      1. FORMA DISTINTA por estado (● lleno / ◐ medio / ○ hueco), no solo
         color. WCAG 1.4.1: el color nunca es el único portador de significado.
         Las formas se dibujan en SVG, no con caracteres: un lector de pantalla
         leería «círculo negro» y un tipo de letra sin el glifo mostraría ▯.
      2. TEXTO SIEMPRE VISIBLE junto al punto. El punto nunca va solo.
      3. Vive en una región role="status" SEPARADA de la región de datos, para
         que un cambio de conexión no se mezcle con las actualizaciones de
         resultados (§9.4 regla 3).

    role: status en todos los estados salvo `offline`, que usa alert (§6.14).
    No es enfocable: es informativo (orden de foco de §9.2).

    El cambio de estado lo dispara window.EncuestaLive.setConnectionState().
    QUIÉN lo llama (Echo/Reverb con respaldo wire:poll de ADR-0002) es trabajo
    de la Fase 5b: sin backend no hay socket real que observar.
--}}

@php
    $states = [
        'online' => [
            'fg' => 'text-connection-online',
            'label' => 'Conectado',
            'role' => 'status',
        ],
        'reconnecting' => [
            'fg' => 'text-connection-reconnecting',
            'label' => 'Reconectando…',
            'role' => 'status',
        ],
        'offline' => [
            'fg' => 'text-connection-offline',
            'label' => 'Sin conexión',
            'role' => 'alert',
        ],
        'stale' => [
            'fg' => 'text-connection-stale',
            'label' => 'Sin confirmar',
            'role' => 'status',
        ],
    ];

    $s = $states[$state] ?? $states['online'];
@endphp

<span
    {{ $attributes->class('inline-flex items-center gap-2 text-sm font-medium ' . $s['fg']) }}
    data-connection="{{ $state }}"
    role="{{ $s['role'] }}"
    @if ($s['role'] === 'status') aria-live="polite" @endif
>
    {{-- Forma 1/3: ● lleno --}}
    @if ($state === 'online')
        <svg width="12" height="12" viewBox="0 0 12 12" aria-hidden="true" class="shrink-0">
            <circle cx="6" cy="6" r="5" fill="currentColor" />
        </svg>

    {{-- Forma 2/3: ◐ medio + spinner. El spinner comunica «en curso»; bajo
         prefers-reduced-motion se detiene y queda el semicírculo. --}}
    @elseif ($state === 'reconnecting')
        <svg width="12" height="12" viewBox="0 0 12 12" aria-hidden="true" class="el-spinner shrink-0">
            <circle cx="6" cy="6" r="5" fill="none" stroke="currentColor" stroke-width="2" />
            <path d="M6 1 A5 5 0 0 1 6 11 Z" fill="currentColor" />
        </svg>

    {{-- Forma 3/3: ○ hueco --}}
    @elseif ($state === 'offline')
        <svg width="12" height="12" viewBox="0 0 12 12" aria-hidden="true" class="shrink-0">
            <circle cx="6" cy="6" r="4.5" fill="none" stroke="currentColor" stroke-width="2" />
        </svg>

    {{-- Obsoleto: ● atenuado (relleno + trama) --}}
    @else
        <svg width="12" height="12" viewBox="0 0 12 12" aria-hidden="true" class="shrink-0">
            <circle cx="6" cy="6" r="5" fill="currentColor" opacity="0.45" />
            <circle cx="6" cy="6" r="5" fill="none" stroke="currentColor" stroke-width="1.5" />
        </svg>
    @endif

    <span>
        {{ $slot->isEmpty() ? $s['label'] : $slot }}@if ($detail)<span class="font-normal" data-connection-detail> · {{ $detail }}</span>@endif
    </span>
</span>
