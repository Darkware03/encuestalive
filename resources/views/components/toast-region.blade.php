{{--
    toast-region — contenedor de los toasts (§6.10).

    Posición: esquina INFERIOR derecha en escritorio, SUPERIOR en móvil.
    Abajo en móvil chocaría con la barra de acción fija del participante.

    z-index: el más alto de la escala cerrada de design-tokens (zIndex.toast = 60).
    pointer-events-none en el contenedor para no bloquear el contenido de debajo;
    cada toast reactiva los suyos.
--}}

<div
    {{ $attributes->class(
        'pointer-events-none fixed inset-x-4 top-4 flex flex-col items-center gap-3 '
        . 'sm:inset-x-auto sm:bottom-4 sm:right-4 sm:top-auto sm:items-end'
    ) }}
    style="z-index: var(--z-toast)"
    data-toast-region
>
    {{ $slot }}
</div>
