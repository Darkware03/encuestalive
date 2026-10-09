@props([
    /* success | info | warning | danger */
    'tone' => 'info',
    'title' => null,
    'icon' => null,
    /* true → role="alert" (assertive). Solo para errores: aria-live assertive
       NUNCA para datos (§9.4 regla 4). */
    'assertive' => false,
])

{{--
    alert — banda fija de estado (wireframes-states 18.a, 19.b, 19.c).

    Se usa para:
      18.a  «○ Sin conexión en tiempo real · mostrando datos de hace 3 min»
      19.b  «◐ Reconectando… intento 2 de 5»
      19.c  «✓ Conexión restablecida · datos actualizados» (4 s, desaparece)

    REGLA CRÍTICA: esta banda EMPUJA el contenido, no se superpone. Si se
    superpusiera taparía datos del dashboard. Por eso no es `fixed`: se coloca
    en el flujo, bajo la barra superior.

    Los KPI y gráficos NO se vacían al perder la conexión: mantienen el último
    valor conocido atenuado. Poner los contadores a 0 sería comunicar un dato
    falso. Eso lo aplica la pantalla (Fase 5b), no este componente.
--}}

@php
    $tones = [
        'success' => ['bg' => 'bg-success-50 border-success-700', 'fg' => 'text-success-700', 'icon' => 'check-circle'],
        'info' => ['bg' => 'bg-info-50 border-info-700', 'fg' => 'text-info-700', 'icon' => 'information-circle'],
        'warning' => ['bg' => 'bg-warning-50 border-warning-800', 'fg' => 'text-warning-800', 'icon' => 'exclamation-triangle'],
        'danger' => ['bg' => 'bg-danger-50 border-danger-700', 'fg' => 'text-danger-700', 'icon' => 'exclamation-triangle'],
    ];

    $t = $tones[$tone] ?? $tones['info'];
@endphp

<div
    {{ $attributes->class('flex flex-wrap items-start gap-3 border-b-2 px-4 py-3 ' . $t['bg']) }}
    role="{{ $assertive ? 'alert' : 'status' }}"
    @unless ($assertive) aria-live="polite" @endunless
>
    <span class="mt-0.5 {{ $t['fg'] }}">
        <x-icon :name="$icon ?? $t['icon']" :size="20" />
    </span>

    <div class="min-w-0 flex-1">
        @if ($title)
            <p class="text-base font-semibold {{ $t['fg'] }}">{{ $title }}</p>
        @endif
        <div class="text-sm text-ink-700">{{ $slot }}</div>
    </div>

    @isset($action)
        <div class="shrink-0">{{ $action }}</div>
    @endisset
</div>
