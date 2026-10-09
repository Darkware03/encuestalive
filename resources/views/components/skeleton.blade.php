@props([
    /* text | title | block | circle | kpi | chart-bar */
    'shape' => 'text',
    'width' => null,
    'height' => null,
    'lines' => 1,
])

{{--
    skeleton — §6.12

    bg ink-100 con brillo ink-300 desplazándose, 1,4 s ease-in-out.
    Bajo prefers-reduced-motion: COLOR PLANO, sin animación (riesgo A9).
    Ambas cosas las resuelve la clase .el-skeleton en resources/css/app.css.

    Debe reproducir la FORMA Y LA ALTURA REALES del contenido que sustituye:
    si no, se produce el salto de diseño (CLS) que el brief prohíbe.

    ACCESIBILIDAD: el CONTENEDOR lleva aria-busy="true" y un texto oculto
    «Cargando…»; los bloques individuales son aria-hidden="true".
    Usa x-skeleton-group para no olvidarlo.
--}}

@php
    $shapes = [
        'text' => 'h-4 rounded-sm',
        'title' => 'h-6 rounded-sm',
        'block' => 'h-24 rounded-lg',
        'circle' => 'h-10 w-10 rounded-full',
        'kpi' => 'h-9 rounded-sm',
        'chart-bar' => 'h-8 rounded-sm',
    ];

    $style = trim(
        ($width ? "width: {$width};" : '') . ($height ? "height: {$height};" : '')
    );
@endphp

@if ($lines > 1)
    <span class="flex flex-col gap-2" aria-hidden="true">
        @for ($i = 0; $i < $lines; $i++)
            <span
                {{ $attributes->class('el-skeleton block ' . ($shapes[$shape] ?? $shapes['text'])) }}
                @style([$style => (bool) $style, 'width: ' . ($i === $lines - 1 ? '60%' : '100%') => ! $width])
            ></span>
        @endfor
    </span>
@else
    <span
        {{ $attributes->class('el-skeleton block ' . ($shapes[$shape] ?? $shapes['text'])) }}
        @if ($style) style="{{ $style }}" @endif
        aria-hidden="true"
    ></span>
@endif
