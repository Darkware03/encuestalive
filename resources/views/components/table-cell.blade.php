@props([
    /* true → <th scope="row"> en lugar de <td>: la primera celda de cada fila
       debe identificar la fila para el lector de pantalla (§9.3). */
    'header' => false,
    'numeric' => false,
])

@php
    /* min-h no funciona en celdas de tabla; la altura de 52px (§6.8) se
       consigue con el padding vertical sobre una línea de 20px. */
    $classes = 'px-4 py-4 align-middle text-sm text-ink-800';
    if ($numeric) {
        $classes .= ' text-right tabular';
    }
    if ($header) {
        $classes .= ' font-semibold text-ink-900';
    }
@endphp

@if ($header)
    <th scope="row" {{ $attributes->class($classes) }}>{{ $slot }}</th>
@else
    <td {{ $attributes->class($classes) }}>{{ $slot }}</td>
@endif
