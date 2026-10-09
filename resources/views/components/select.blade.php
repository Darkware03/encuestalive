@props([
    'id' => null,
    'name' => null,
    'label' => null,
    'hint' => null,
    'error' => null,
    'required' => false,
    'disabled' => false,
    /* array asociativo valor => etiqueta */
    'options' => [],
    'selected' => null,
    'placeholder' => null,
])

{{--
    select — §6.2 (mismos estados que el input).

    <select> NATIVO a propósito (§9.3: «se prefiere HTML nativo a ARIA»).
    En móvil abre la rueda del sistema, que es más accesible y más rápida que
    cualquier desplegable hecho a mano, y funciona sin JavaScript.

    La flecha ▾ es decorativa (aria-hidden) y no intercepta el puntero.
    16px mínimo de tamaño de fuente: evita el zoom automático de iOS (A4).
--}}

@php
    $id = $id ?? $name ?? 'select-' . Str::random(6);
    $hintId = $hint ? $id . '-hint' : null;
    $errorId = $error ? $id . '-error' : null;
    $describedBy = trim(implode(' ', array_filter([$hintId, $errorId]))) ?: null;

    $field = 'w-full h-11 appearance-none rounded-md bg-white pl-4 pr-11 text-base text-ink-900 '
        . 'transition-colors motion-safe:duration-[var(--duration-fast)] '
        . 'focus-visible:outline-2 focus-visible:outline-offset-2 '
        . 'disabled:cursor-not-allowed disabled:bg-state-disabled-bg '
        . 'disabled:text-state-disabled-fg disabled:border-ink-200';

    $field .= $error
        ? ' border-2 border-danger-700 focus-visible:outline-danger-700'
        : ' border border-border-strong hover:border-ink-600 '
            . 'focus-visible:border-brand-700 focus-visible:outline-brand-700';
@endphp

<div {{ $attributes->only('class')->class('w-full') }}>
    @if ($label)
        <label for="{{ $id }}" class="mb-2 block text-sm font-semibold text-ink-700">
            {{ $label }}
            @if ($required)
                <span class="text-danger-700" aria-hidden="true">*</span>
            @endif
        </label>
    @endif

    <div class="relative">
        <select
            {{ $attributes->except('class')->class($field) }}
            id="{{ $id }}"
            @if ($name) name="{{ $name }}" @endif
            @if ($required) required aria-required="true" @endif
            @disabled($disabled)
            @if ($disabled) aria-disabled="true" @endif
            @if ($error) aria-invalid="true" @endif
            @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
        >
            @if ($placeholder)
                <option value="" disabled @selected($selected === null)>{{ $placeholder }}</option>
            @endif
            @foreach ($options as $val => $text)
                <option value="{{ $val }}" @selected((string) $selected === (string) $val)>{{ $text }}</option>
            @endforeach
        </select>

        <span class="pointer-events-none absolute inset-y-0 right-0 flex w-11 items-center justify-center {{ $disabled ? 'text-state-disabled-fg' : 'text-ink-700' }}">
            <x-icon name="chevron-down" :size="20" />
        </span>
    </div>

    @if ($hint)
        <p id="{{ $hintId }}" class="mt-1 text-sm text-ink-500">{{ $hint }}</p>
    @endif

    <x-field-error :id="$errorId" :message="$error" />
</div>
