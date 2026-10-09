@props([
    'id',
    'title',
    /* true → Esc NO cierra (flujo destructivo a medias, §6.11) */
    'locked' => false,
    /* Oculto por defecto; la galería lo abre con :open para poder capturarlo */
    'open' => false,
    /* Sin botón de cerrar: p. ej. «Tu sesión expiró» (18.c), donde no hay
       nada útil detrás */
    'dismissible' => true,
])

{{--
    modal — §6.11 · §9.3

    Comportamiento obligatorio (implementado en resources/js/app.js):
      · foco ATRAPADO dentro
      · foco inicial en el primer control NO destructivo
        (marcar con data-modal-initial-focus; en un diálogo de borrado, «Cancelar»)
      · Esc cierra, salvo flujo destructivo a medias
      · al cerrar, el foco VUELVE al elemento que lo abrió (WCAG 2.4.3)
      · aria-hidden + inert en el fondo ([data-modal-background])
      · scroll del body bloqueado

    RESPONSIVE: por debajo de md es una HOJA INFERIOR a ancho completo con
    rounded-t-xl pegada al borde inferior; desde md, modal centrado.
--}}

<div
    id="{{ $id }}"
    class="fixed inset-0 flex items-end justify-center md:items-center md:p-4"
    style="z-index: var(--z-modal)"
    data-modal
    @if ($locked) data-modal-locked="true" @endif
    @unless ($open) hidden @endunless
>
    {{-- Scrim rgba(21,26,35,0.55). Clicar fuera cierra, salvo si está bloqueado. --}}
    <div
        class="el-scrim absolute inset-0"
        @unless ($locked) data-modal-close @endunless
        aria-hidden="true"
    ></div>

    <div
        {{ $attributes->class(
            'relative flex max-h-[90vh] w-full max-w-lg flex-col rounded-t-xl bg-white shadow-lg md:rounded-xl'
        ) }}
        role="dialog"
        aria-modal="true"
        aria-labelledby="{{ $id }}-title"
        @isset($description) aria-describedby="{{ $id }}-desc" @endisset
    >
        <div class="flex items-start justify-between gap-4 p-6 pb-0">
            <h2 id="{{ $id }}-title" class="text-2xl font-semibold text-ink-900">{{ $title }}</h2>

            @if ($dismissible)
                <button
                    type="button"
                    class="-m-2 -mt-1 inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-md text-ink-700
                           transition-colors hover:bg-ink-100
                           focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-700"
                    data-modal-close
                    aria-label="Cerrar diálogo"
                >
                    <x-icon name="x-mark" :size="20" />
                </button>
            @endif
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto p-6 text-base text-ink-800" @isset($description) id="{{ $id }}-desc" @endisset>
            {{ $slot }}
        </div>

        @isset($footer)
            <div class="flex flex-col gap-3 border-t border-ink-200 p-6 sm:flex-row sm:justify-end">
                {{ $footer }}
            </div>
        @endisset
    </div>
</div>
