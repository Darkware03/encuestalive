@props([
    /* radio | checkbox */
    'type' => 'radio',
    'name',
    'value' => '',
    'id' => null,
    'checked' => false,
    'disabled' => false,
    /* Texto de apoyo opcional bajo la etiqueta */
    'description' => null,
])

{{--
    choice-option — fila de opción de respuesta (§6.3 radio · §6.4 checkbox).

    Decisiones aplicadas:
      · <input> NATIVO con appearance-none y estilo propio (.el-control-dot).
        NO <div role="radio">: el nativo ya aporta grupo, navegación con
        flechas y anuncio correcto.
      · TODA LA FILA es el <label> → objetivo táctil de 48px de alto y ancho
        completo (§9.5, supera con holgura los 24px de WCAG 2.2 §2.5.8).
      · El anillo de foco rodea LA FILA COMPLETA, no el círculo: lo hace
        `.el-option-row:has(.el-control-dot:focus-visible)` en app.css.
      · checked: borde brand-700 de 2px + fondo brand-50.
      · El estado `error` NO vive aquí: vive en el <fieldset> (x-choice-group).
      · `loading` no aplica a una opción suelta; el grupo entero usa aria-busy.
--}}

@php
    $id = $id ?? $name . '-' . Str::slug((string) $value ?: Str::random(5));
    $descId = $description ? $id . '-desc' : null;

    /* Se usa border-2 SIEMPRE (no solo en checked) para que la fila no cambie
       de tamaño al seleccionarla: un salto de 1px desplaza toda la lista. */
    $row = 'el-option-row target-comfortable relative flex w-full cursor-pointer items-center gap-3 '
        . 'rounded-lg border-2 px-4 py-3 text-base '
        . 'transition-colors motion-safe:duration-[var(--duration-fast)] '
        . 'ease-[var(--ease-standard)]';

    if ($disabled) {
        $row .= ' cursor-not-allowed border-ink-200 bg-ink-100 text-state-disabled-fg';
    } elseif ($checked) {
        $row .= ' border-brand-700 bg-brand-50 text-ink-900';
    } else {
        $row .= ' border-ink-200 bg-white text-ink-900 hover:bg-ink-50 hover:border-ink-300';
    }
@endphp

<label
    for="{{ $id }}"
    {{ $attributes->class($row) }}
    @if ($disabled) data-disabled @endif
>
    <input
        class="el-control-dot"
        type="{{ $type }}"
        id="{{ $id }}"
        name="{{ $name }}"
        value="{{ $value }}"
        @checked($checked)
        @disabled($disabled)
        @if ($disabled) aria-disabled="true" @endif
        @if ($descId) aria-describedby="{{ $descId }}" @endif
    />

    <span class="min-w-0 flex-1">
        <span class="block" data-user-text>{{ $slot }}</span>
        @if ($description)
            <span id="{{ $descId }}" class="mt-0.5 block text-sm {{ $disabled ? 'text-state-disabled-fg' : 'text-ink-600' }}">
                {{ $description }}
            </span>
        @endif
    </span>
</label>
