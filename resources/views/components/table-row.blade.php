@props([
    'selected' => false,
    'disabled' => false,
])

{{--
    table-row — fila de x-table (§6.8).

    · altura mínima 52px
    · hover ink-50 · fila seleccionada bg-brand-50
    · disabled: opacity-60 y las acciones se RETIRAN del DOM, no solo se ocultan
      (lo decide el consumidor: aquí solo se marca el estado)
    · el anillo de foco va en el elemento interactivo de la fila, no en la fila
--}}

@php
    $classes = 'transition-colors motion-safe:duration-[var(--duration-fast)]';

    if ($disabled) {
        $classes .= ' opacity-60';
    } elseif ($selected) {
        $classes .= ' bg-brand-50';
    } else {
        $classes .= ' bg-white hover:bg-ink-50';
    }
@endphp

<tr
    {{ $attributes->class($classes) }}
    @if ($selected) aria-selected="true" @endif
    @if ($disabled) aria-disabled="true" @endif
>
    {{ $slot }}
</tr>
