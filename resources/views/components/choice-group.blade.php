@props([
    /* Enunciado de la pregunta. Va en <legend>, no en un <p> suelto. */
    'legend',
    'hint' => null,
    'error' => null,
    'required' => false,
    'disabled' => false,
    /* Al enviar, el grupo entero puede entrar en aria-busy (§6.3 loading) */
    'loading' => false,
    /* Límites de selección múltiple (§6.4). Si hay max, el JS deshabilita
       las no marcadas al alcanzarlo: prevenir el error > informarlo (3.3.4). */
    'min' => null,
    'max' => null,
    'count' => 0,
    'id' => null,
])

{{--
    choice-group — agrupación OBLIGATORIA de radios y checkboxes (§6.3 · §9.3).

    Sin <fieldset> + <legend>, un lector de pantalla lee «Excelente, botón de
    radio» sin saber de qué pregunta se trata.

    El error del grupo se muestra AQUÍ, no en cada opción: borde izquierdo
    danger-700 de 3px + mensaje bajo la leyenda.
--}}

@php
    $id = $id ?? 'group-' . Str::random(6);
    $hintId = $hint ? $id . '-hint' : null;
    $errorId = $error ? $id . '-error' : null;
    $liveId = $id . '-live';
    $describedBy = trim(implode(' ', array_filter([$hintId, $errorId]))) ?: null;
@endphp

<fieldset
    {{ $attributes->class([
        'min-w-0 border-0 p-0',
        'border-l-[3px] border-danger-700 pl-4' => (bool) $error,
    ]) }}
    id="{{ $id }}"
    @disabled($disabled)
    @if ($loading) aria-busy="true" @endif
    @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
    @if ($error) aria-invalid="true" @endif
    @if ($max)
        data-choice-group
        data-choice-min="{{ $min ?? 0 }}"
        data-choice-max="{{ $max }}"
    @endif
>
    <legend class="mb-1 text-lg font-semibold text-ink-900">
        {{ $legend }}
        @if ($required)
            <span class="text-danger-700" aria-hidden="true">*</span>
        @endif
    </legend>

    @if ($hint)
        <p id="{{ $hintId }}" class="mb-3 text-sm text-ink-600">{{ $hint }}</p>
    @endif

    @if ($error)
        <x-field-error :id="$errorId" :message="$error" class="mb-3" />
    @endif

    @if ($max)
        {{-- Contador en vivo del §6.4. aria-live separado del contenido visible
             para que el lector no reciba dos anuncios por el mismo cambio. --}}
        <p class="mb-3 text-sm text-ink-600">
            Elige entre {{ $min ?? 1 }} y {{ $max }} · llevas
            <span class="tabular font-semibold" data-choice-count>{{ $count }}</span>
        </p>
        <p id="{{ $liveId }}" class="sr-only" aria-live="polite" data-choice-live></p>
    @endif

    {{-- §5.4: 12px entre opciones de respuesta del participante --}}
    <div class="flex flex-col gap-3">
        {{ $slot }}
    </div>
</fieldset>
