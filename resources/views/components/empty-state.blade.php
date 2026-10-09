@props([
    /* no-surveys | no-results | awaiting-responses | no-text-answers */
    'variant' => 'no-surveys',
    'title' => null,
    'description' => null,
    'icon' => null,
])

{{--
    empty-state — §6.13

    CUATRO variantes con MENSAJES DISTINTOS. Un estado vacío genérico
    («No hay datos») no ayuda a nadie: no dice qué hacer a continuación.

    Estructura fija: icono decorativo 48px · título xl/600 · explicación sm
    ink-600 (máx. ~40 caracteres por línea) · UNA acción primaria como mucho.
    Sin ilustraciones decorativas.

    NOTA DE COLOR: el icono usa ink-500 (4,88:1), no ink-400. ink-400 es
    DECORATIVO (2,62:1) y aquí el icono acompaña a un mensaje con significado.
--}}

@php
    $presets = [
        'no-surveys' => [
            'icon' => 'inbox',
            'title' => 'Aún no tienes encuestas',
            'description' => 'Crea la primera y compártela con un código QR.',
        ],
        'no-results' => [
            'icon' => 'magnifying-glass',
            'title' => 'Sin resultados',
            'description' => 'Prueba con otras palabras o quita los filtros.',
        ],
        'awaiting-responses' => [
            'icon' => 'qr-code',
            'title' => 'Esperando respuestas',
            'description' => 'Muestra el código QR para que empiecen a participar.',
        ],
        'no-text-answers' => [
            'icon' => 'document-text',
            'title' => 'Nadie ha escrito todavía',
            'description' => 'Las respuestas de texto libre aparecerán aquí.',
        ],
    ];

    $p = $presets[$variant] ?? $presets['no-surveys'];
    $icon = $icon ?? $p['icon'];
    $title = $title ?? $p['title'];
    $description = $description ?? $p['description'];
@endphp

<div {{ $attributes->class('flex flex-col items-center rounded-lg border border-ink-200 bg-white px-6 py-12 text-center') }}>
    <span class="text-ink-500">
        <x-icon :name="$icon" :size="48" />
    </span>

    <p class="mt-4 text-xl font-semibold text-ink-900">{{ $title }}</p>

    @if ($description)
        <p class="mt-1 max-w-[40ch] text-sm text-ink-600">{{ $description }}</p>
    @endif

    @if (! $slot->isEmpty())
        <div class="mt-6">{{ $slot }}</div>
    @endif
</div>
