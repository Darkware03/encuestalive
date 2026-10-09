@props([
    'label',
    'count' => 0,
    'percent' => 0,
    /* 1..6 — serie de la paleta chart. Con más de 6 opciones se repite el
       ciclo diferenciando por etiqueta (y por trama, pendiente de 5b). */
    'series' => 1,
])

{{--
    result-bar — barra horizontal de distribución por opción (§6.9).

    Anatomía obligatoria: ETIQUETA · BARRA · CONTEO · PORCENTAJE.
    Los tres datos en TEXTO. El color nunca es el único portador de
    significado (WCAG 1.4.1): aunque alguien no distinga las series, lee
    el nombre de la opción y su cifra.

    Barras HORIZONTALES (no verticales) para que las etiquetas largas se lean
    sin rotar texto. Altura 32px, rounded-sm, pista ink-100.

    Cada barra es alcanzable con teclado y anuncia el conjunto completo
    (§6.9 focus-visible).

    ALCANCE: este componente solo PINTA valores que le pasan. El cálculo del
    porcentaje —y muy en particular la base de la selección múltiple de D-06,
    que puede superar el 100 % y obliga a declarar el denominador en pantalla—
    corresponde a StatsService en la Fase 5b. Aquí no se calcula nada.
--}}

@php
    $pct = max(0, min(100, (float) $percent));
    $fills = [
        1 => 'bg-chart-1', 2 => 'bg-chart-2', 3 => 'bg-chart-3',
        4 => 'bg-chart-4', 5 => 'bg-chart-5', 6 => 'bg-chart-6',
    ];
    $fill = $fills[(($series - 1) % 6) + 1];
    $announce = $label . ', ' . $count . ' respuestas, ' . str_replace('.', ',', (string) $percent) . ' por ciento';
@endphp

<li
    {{ $attributes->class('group rounded-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-700') }}
    tabindex="0"
    aria-label="{{ $announce }}"
>
    <div class="flex items-baseline justify-between gap-4">
        <span class="min-w-0 truncate text-base text-ink-900" data-user-text>{{ $label }}</span>
        <span class="shrink-0 text-sm tabular text-ink-600">
            <span class="font-semibold text-ink-900">{{ $count }}</span>
            · {{ str_replace('.', ',', (string) $percent) }} %
        </span>
    </div>

    <div class="mt-1.5 h-8 w-full overflow-hidden rounded-sm bg-ink-100" aria-hidden="true">
        <div class="el-chart-bar-fill h-full rounded-sm {{ $fill }}" style="width: {{ $pct }}%"></div>
    </div>
</li>
