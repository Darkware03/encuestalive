@props([
    /* neutral | info | success | warning | danger | brand */
    'tone' => 'neutral',
    'icon' => null,
])

{{--
    badge — píldora informativa genérica (distinta de x-status-badge, que es
    exclusivamente el estado de la encuesta, §6.7).

    Se usa para etiquetas como «Obligatoria», «Anónima», «+37 mientras estabas
    sin conexión». Igual que el badge de estado: SIEMPRE lleva palabra, nunca
    solo color. No es accionable.
--}}

@php
    $tones = [
        'neutral' => 'bg-ink-100 text-ink-700',
        'info' => 'bg-info-50 text-info-700',
        'success' => 'bg-success-50 text-success-700',
        'warning' => 'bg-warning-50 text-warning-800',
        'danger' => 'bg-danger-50 text-danger-700',
        'brand' => 'bg-brand-50 text-brand-700',
    ];
@endphp

<span {{ $attributes->class('inline-flex items-center gap-1 rounded-sm px-2 py-0.5 text-xs font-semibold ' . ($tones[$tone] ?? $tones['neutral'])) }}>
    @if ($icon)
        <x-icon :name="$icon" :size="14" />
    @endif
    {{ $slot }}
</span>
