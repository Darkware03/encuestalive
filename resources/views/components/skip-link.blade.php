@props([
    'target' => 'contenido',
])

{{--
    skip-link — «Saltar al contenido» (§9.1).

    Debe ser el PRIMER elemento enfocable de la página y hacerse VISIBLE al
    recibir el foco. Un enlace de salto permanentemente invisible (sr-only
    sin estado de foco) es un fallo de 2.4.1: existe para el lector de
    pantalla pero no para quien navega con teclado y ve la pantalla.
--}}

<a
    href="#{{ $target }}"
    {{ $attributes->class(
        'sr-only-focusable absolute left-4 top-4 inline-flex h-11 items-center rounded-md '
        . 'bg-brand-700 px-5 text-base font-semibold text-white '
        . 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-700'
    ) }}
    style="z-index: var(--z-sticky)"
>
    {{ $slot->isEmpty() ? 'Saltar al contenido' : $slot }}
</a>
