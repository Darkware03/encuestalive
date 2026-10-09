@props([
    'id',
    'title',
    /* Referencia a la sección de UX_UI_SPEC.md que especifica el componente */
    'spec' => null,
    'note' => null,
])

{{-- gallery-section — bloque de la galería. Solo estructura, cero adorno. --}}

<section id="{{ $id }}" {{ $attributes->class('mb-12 scroll-mt-8') }}>
    <div class="mb-4 border-b border-ink-200 pb-3">
        <div class="flex flex-wrap items-baseline gap-3">
            <h2 class="text-2xl font-semibold text-ink-900">{{ $title }}</h2>
            @if ($spec)
                <span class="font-mono text-xs text-ink-500">UX_UI_SPEC {{ $spec }}</span>
            @endif
        </div>
        @if ($note)
            <p class="mt-2 max-w-prose text-sm text-ink-600">{{ $note }}</p>
        @endif
    </div>

    {{ $slot }}
</section>
