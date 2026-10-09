@props([
    'id' => null,
    'name' => null,
    'label' => null,
    'hint' => null,
    'error' => null,
    'required' => false,
    'disabled' => false,
    'placeholder' => null,
    'value' => '',
    'maxlength' => 500,
    'rows' => 4,
    /* Permite forzar el umbral para la galería de estados sin escribir texto */
    'demoLength' => null,
])

{{--
    textarea con contador — §6.5

    Umbrales del contador:
      0–89 %   ink-500   · sin anuncio
      90–99 %  warning-800 · «Quedan N caracteres» (polite, máx. 1/s)
      100 %    danger-700 · «Has alcanzado el límite de N caracteres»

    El contador NUNCA anuncia en cada pulsación (riesgo A6): la limitación
    de frecuencia vive en resources/js/app.js → initCharacterCounters().
    El texto se TRUNCA en el límite (maxlength nativo).

    Estados default/hover/focus-visible/disabled/error idénticos al input (§6.2).
--}}

@php
    $id = $id ?? $name ?? 'textarea-' . Str::random(6);
    $hintId = $hint ? $id . '-hint' : null;
    $errorId = $error ? $id . '-error' : null;
    $counterId = $id . '-counter';
    $describedBy = trim(implode(' ', array_filter([$hintId, $counterId, $errorId]))) ?: null;

    $length = $demoLength ?? mb_strlen((string) $value);
    $pct = $maxlength > 0 ? ($length / $maxlength) * 100 : 0;
    $threshold = $pct >= 100 ? 'full' : ($pct >= 90 ? 'near' : 'ok');

    $thresholdClass = [
        'ok' => 'text-ink-500',
        'near' => 'text-warning-800',
        'full' => 'text-danger-700 font-semibold',
    ][$threshold];

    $field = 'w-full min-h-[120px] resize-y rounded-md bg-white px-4 py-3 text-base text-ink-900 '
        . 'placeholder:text-state-placeholder '
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

    {{-- A8: texto libre sin espacios no puede romper el diseño --}}
    <textarea
        {{ $attributes->except('class')->class($field) }}
        id="{{ $id }}"
        @if ($name) name="{{ $name }}" @endif
        rows="{{ $rows }}"
        maxlength="{{ $maxlength }}"
        data-user-text
        @if ($placeholder) placeholder="{{ $placeholder }}" @endif
        @if ($required) required aria-required="true" @endif
        @disabled($disabled)
        @if ($disabled) aria-disabled="true" @endif
        @if ($error) aria-invalid="true" @endif
        @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
    >{{ $value }}</textarea>

    <div class="mt-1 flex items-start justify-between gap-4">
        <div class="min-w-0">
            @if ($hint)
                <p id="{{ $hintId }}" class="text-sm text-ink-500">{{ $hint }}</p>
            @endif
            <x-field-error :id="$errorId" :message="$error" />
        </div>

        <p
            id="{{ $counterId }}"
            class="shrink-0 text-sm tabular {{ $thresholdClass }}"
            data-counter-for="{{ $id }}"
            data-threshold="{{ $threshold }}"
        >
            <span data-counter-value>{{ $length }}/{{ $maxlength }}</span>
            {{-- Región de anuncio limitada: la rellena el JS, no el servidor --}}
            <span class="sr-only" data-counter-live aria-live="polite"></span>
        </p>
    </div>
</div>
