@props([
    /* primary | secondary | outline | ghost | danger  (UX_UI_SPEC §6.1) */
    'variant' => 'primary',
    /* sm 32px · md 44px (estándar admin) · lg 48px (participante, §9.5) */
    'size' => 'md',
    'type' => 'button',
    'href' => null,
    'disabled' => false,
    /* Estado `loading`: spinner + GERUNDIO del mismo verbo + ancho congelado
       + aria-busy + disabled (wireframes-states 16.c). */
    'loading' => false,
    'loadingLabel' => null,
    /* Ancho completo: obligatorio en la acción primaria móvil (§10) */
    'block' => false,
    /* Botón solo-icono: exige :label para que tenga nombre accesible */
    'iconOnly' => false,
    'label' => null,
    'icon' => null,
])

{{--
    button — §6.1

    Estado `error`: NO APLICA. Un botón no tiene estado de error; el error vive
    en el formulario (x-field-error / x-error-summary) o en un toast.

    Reglas aplicadas:
      · altura mínima 44px en toda acción (48px en el participante)
      · el texto es un VERBO EN INFINITIVO
      · en loading el ancho se congela (resources/js/app.js → freezeButtonWidths)
      · nunca cursor:pointer si no es accionable → disabled usa cursor-not-allowed
      · sin desplazamiento ni escala en :active (solo cambia el relleno)
--}}

@php
    $isDisabled = $disabled || $loading;

    $base = 'el-btn inline-flex items-center justify-center gap-2 font-semibold '
        . 'rounded-md border transition-colors '
        . 'motion-safe:duration-[var(--duration-fast)] ease-[var(--ease-standard)] '
        . 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-700 '
        . 'select-none';

    $sizes = [
        'sm' => $iconOnly ? 'h-8 w-8 min-h-8 text-sm' : 'h-8 px-3 text-sm',
        // 44px — estándar admin (§9.5). El icono dibuja 20px pero el área táctil es 44.
        'md' => $iconOnly ? 'h-11 w-11 min-h-11 min-w-11 text-base' : 'h-11 px-5 text-base',
        // 48px — acción primaria del participante (§9.5)
        'lg' => $iconOnly ? 'h-12 w-12 min-h-12 min-w-12 text-base' : 'h-12 px-6 text-base',
    ];

    /* Los hex no se escriben aquí: todas las clases salen de los tokens
       generados en resources/css/tokens.css (bg-brand-700 == #1B3AA8). */
    $variants = [
        'primary' => 'border-transparent bg-brand-700 text-white '
            . 'hover:bg-brand-800 active:bg-brand-900',
        'secondary' => 'border-transparent bg-brand-50 text-brand-700 '
            . 'hover:bg-brand-100 active:bg-brand-200',
        'outline' => 'border-border-strong bg-white text-ink-800 '
            . 'hover:bg-ink-50 hover:border-ink-600 active:bg-ink-100',
        'ghost' => 'border-transparent bg-transparent text-ink-700 '
            . 'hover:bg-ink-100 active:bg-ink-200',
        'danger' => 'border-transparent bg-danger-700 text-white '
            . 'hover:bg-danger-600 active:bg-[#8E1E18]',
    ];

    /*
     * disabled: #EDF1F7 / #626A79 — 4,80:1, verificado en §5.2.
     *
     * DEFECTO CORREGIDO TRAS VER EL RENDER (Fase 5a):
     * un botón en `loading` también lleva el atributo `disabled` (para que no
     * se pueda pulsar dos veces), así que heredaba estas clases y se pintaba
     * GRIS. En la captura, «Publicando…» era indistinguible de un botón no
     * disponible: se perdía la señal de «esto está trabajando».
     * Ahora el aspecto apagado solo se aplica cuando está realmente
     * deshabilitado; en carga el botón conserva el color de su variante.
     */
    $disabledClasses = $loading
        ? 'disabled:cursor-wait'
        : 'disabled:cursor-not-allowed disabled:border-transparent '
            . 'disabled:bg-state-disabled-bg disabled:text-state-disabled-fg '
            . 'disabled:hover:bg-state-disabled-bg';

    $classes = trim(implode(' ', [
        $base,
        $sizes[$size] ?? $sizes['md'],
        $variants[$variant] ?? $variants['primary'],
        $disabledClasses,
        $block ? 'w-full' : '',
    ]));

    $tag = $href && ! $isDisabled ? 'a' : 'button';
@endphp

<{{ $tag }}
    {{ $attributes->class($classes) }}
    @if ($tag === 'a')
        href="{{ $href }}"
    @else
        type="{{ $type }}"
        @disabled($isDisabled)
    @endif
    @if ($loading) aria-busy="true" @endif
    @if ($iconOnly && $label) aria-label="{{ $label }}" @endif
>
    @if ($loading)
        <x-spinner :size="$size === 'sm' ? 14 : 18" :label="null" />
    @elseif ($icon)
        <x-icon :name="$icon" :size="$size === 'sm' ? 16 : 20" />
    @endif

    @if (! $iconOnly)
        <span>{{ $loading ? ($loadingLabel ?? $slot) : $slot }}</span>
    @endif
</{{ $tag }}>
