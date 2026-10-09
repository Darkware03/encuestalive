@props([
    'id' => null,
    'name' => null,
    'type' => 'text',
    /* §6.2 / WCAG 3.3.2: el <label> SIEMPRE visible. Jamás el placeholder
       como etiqueta: desaparece al escribir. */
    'label' => null,
    'hint' => null,
    'error' => null,
    'required' => false,
    'disabled' => false,
    /* Solo para campos con validación remota (§6.2): spinner 16px a la derecha */
    'loading' => false,
    'placeholder' => null,
    'value' => null,
    'autocomplete' => null,
    /* Icono decorativo a la izquierda (buscador, etc.) */
    'leadingIcon' => null,
])

@php
    $id = $id ?? $name ?? 'input-' . Str::random(6);
    $hintId = $hint ? $id . '-hint' : null;
    $errorId = $error ? $id . '-error' : null;
    $describedBy = trim(implode(' ', array_filter([$hintId, $errorId]))) ?: null;

    /*
     * Estados (§6.2). Ningún hex literal: todo sale de tokens.css.
     *   default  border-strong (#78849A, 3.77:1) — border-subtle NO vale como
     *            único límite de un control (nota de design-tokens.json).
     *   error    borde danger-700 de 2px y fondo BLANCO (no rojo: el texto
     *            debe seguir legible).
     *   focus    borde brand-700 + anillo 2px con separación 2px. El borde
     *            por sí solo no cumple 2.4.13.
     */
    $field = 'w-full h-11 rounded-md bg-white px-4 text-base text-ink-900 '
        . 'placeholder:text-state-placeholder '
        . 'transition-colors motion-safe:duration-[var(--duration-fast)] '
        . 'focus-visible:outline-2 focus-visible:outline-offset-2 '
        . 'disabled:cursor-not-allowed disabled:bg-state-disabled-bg '
        . 'disabled:text-state-disabled-fg disabled:border-ink-200';

    $field .= $error
        ? ' border-2 border-danger-700 focus-visible:outline-danger-700'
        : ' border border-border-strong hover:border-ink-600 '
            . 'focus-visible:border-brand-700 focus-visible:outline-brand-700';

    if ($leadingIcon) {
        $field .= ' pl-11';
    }
    if ($loading) {
        $field .= ' pr-11';
    }
@endphp

<div {{ $attributes->only('class')->class('w-full') }}>
    @if ($label)
        <label for="{{ $id }}" class="mb-2 block text-sm font-semibold text-ink-700">
            {{ $label }}
            @if ($required)
                {{-- El asterisco se explica en la leyenda del formulario (§9.3) --}}
                <span class="text-danger-700" aria-hidden="true">*</span>
            @endif
        </label>
    @endif

    <div class="relative">
        @if ($leadingIcon)
            <span class="pointer-events-none absolute inset-y-0 left-0 flex w-11 items-center justify-center text-ink-700">
                <x-icon :name="$leadingIcon" :size="20" />
            </span>
        @endif

        <input
            {{ $attributes->except('class')->class($field) }}
            id="{{ $id }}"
            @if ($name) name="{{ $name }}" @endif
            type="{{ $type }}"
            @if ($placeholder) placeholder="{{ $placeholder }}" @endif
            @if ($value !== null) value="{{ $value }}" @endif
            @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif
            @if ($required) required aria-required="true" @endif
            @disabled($disabled)
            @if ($disabled) aria-disabled="true" @endif
            @if ($error) aria-invalid="true" @endif
            @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
            @if ($loading) aria-busy="true" @endif
        />

        @if ($loading)
            <span class="pointer-events-none absolute inset-y-0 right-0 flex w-11 items-center justify-center text-ink-700">
                <x-spinner :size="16" label="Comprobando" />
            </span>
        @endif
    </div>

    @if ($hint)
        <p id="{{ $hintId }}" class="mt-1 text-sm text-ink-500">{{ $hint }}</p>
    @endif

    <x-field-error :id="$errorId" :message="$error" />
</div>
