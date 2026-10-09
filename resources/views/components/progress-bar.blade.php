@props([
    'value' => 0,
    'max' => 100,
    'min' => 0,
    /* Texto visible obligatorio: «2 de 3». No basta con aria-valuenow. */
    'text' => null,
    'showText' => true,
])

{{--
    progress-bar — §6.15 (progreso del participante)

    role="progressbar" con aria-valuenow / aria-valuemin / aria-valuemax
    Y ADEMÁS el texto «2 de 3»: los atributos ARIA solos no sirven a quien ve
    la pantalla pero no usa lector.

    Altura 4px · pista ink-200 · relleno brand-700 · transición 320ms (`slow`),
    anulada bajo prefers-reduced-motion por la regla global de app.css.

    REGLA DE USO: solo se muestra con ≥3 preguntas en modo «una por pantalla».
    Con 2 preguntas es ruido. Esa condición la aplica la pantalla, no este
    componente.
--}}

@php
    $span = max(1, $max - $min);
    $pct = max(0, min(100, (($value - $min) / $span) * 100));
    $label = $text ?? "{$value} de {$max}";
@endphp

<div {{ $attributes->class('w-full') }}>
    @if ($showText)
        <p class="mb-2 text-sm text-ink-600 tabular">{{ $label }}</p>
    @endif

    <div
        class="h-1 w-full overflow-hidden rounded-full bg-ink-200"
        role="progressbar"
        aria-valuenow="{{ $value }}"
        aria-valuemin="{{ $min }}"
        aria-valuemax="{{ $max }}"
        aria-valuetext="{{ $label }}"
    >
        <div class="el-progress-fill h-full rounded-full bg-brand-700" style="width: {{ $pct }}%"></div>
    </div>
</div>
