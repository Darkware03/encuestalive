@props([
    'title' => null,
    /* Nivel del encabezado: la jerarquía no puede saltar (h2→h4) — §9.1 */
    'headingLevel' => 'h3',
    'subtitle' => null,
    /* Si la tarjeta es clicable debe ser <a> o <button>, nunca un div con onclick */
    'href' => null,
    'disabled' => false,
    'error' => null,
    /* Tarjeta sobre canvas ink-50: solo entonces se permite shadow-sm */
    'raised' => false,
])

{{--
    card — §6.6

    REGLA RESTRICTIVA DEL BRIEF: una tarjeta solo existe si AGRUPA contenido
    relacionado y lo SEPARA de otro grupo. Una tarjeta que envuelve toda la
    página no es una tarjeta: es un fondo, y debe eliminarse.

    Elevación por defecto = borde de 1px, no sombra (§5.6).
    `loading` no es un estado de este componente: la tarjeta se SUSTITUYE por
    su skeleton con la MISMA altura (x-skeleton), para no provocar CLS.
--}}

@php
    $interactive = (bool) $href && ! $disabled;

    $base = 'block rounded-lg border bg-white p-4 md:p-6 text-left';

    if ($error) {
        $base .= ' border-danger-700';
    } else {
        $base .= ' border-ink-200';
    }

    if ($raised) {
        $base .= ' shadow-sm';
    }

    if ($interactive) {
        $base .= ' transition-colors motion-safe:duration-[var(--duration-fast)] '
            . 'hover:border-ink-300 hover:shadow-sm active:bg-ink-50 '
            . 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-700';
    }

    if ($disabled) {
        $base .= ' pointer-events-none opacity-60';
    }

    $tag = $interactive ? 'a' : 'div';
    $h = in_array($headingLevel, ['h2', 'h3', 'h4'], true) ? $headingLevel : 'h3';
@endphp

<{{ $tag }}
    {{ $attributes->class($base) }}
    @if ($interactive) href="{{ $href }}" @endif
    @if ($disabled) aria-disabled="true" @endif
>
    @if ($error)
        {{-- Banda de error interna; el resto del contenido queda intacto (§6.6) --}}
        <p class="mb-4 flex items-start gap-2 rounded-md bg-danger-50 px-3 py-2 text-sm text-danger-700" role="alert">
            <x-icon name="exclamation-triangle" :size="16" class="mt-0.5" />
            <span>{{ $error }}</span>
        </p>
    @endif

    @if ($title)
        <{{ $h }} class="text-xl font-semibold text-ink-900">{{ $title }}</{{ $h }}>
        @if ($subtitle)
            <p class="mt-1 text-sm text-ink-600">{{ $subtitle }}</p>
        @endif
        <div class="mt-4">{{ $slot }}</div>
    @else
        {{ $slot }}
    @endif
</{{ $tag }}>
