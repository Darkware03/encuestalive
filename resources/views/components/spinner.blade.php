@props([
    'size' => 16,
    /* El texto NO se pinta: es para el lector de pantalla. Si el spinner vive
       dentro de un botón que ya dice «Publicando…», pasa :label="null". */
    'label' => 'Cargando',
])

{{--
    spinner — indicador indeterminado.
    §5.9: se detiene bajo prefers-reduced-motion (la clase .el-spinner lo hace).
    §16.a: por debajo de 300 ms no se muestra NADA; este componente es para el
    tramo 300 ms – 1 s dentro del propio control.
--}}

<svg
    {{ $attributes->class(['el-spinner shrink-0']) }}
    width="{{ $size }}"
    height="{{ $size }}"
    viewBox="0 0 24 24"
    fill="none"
    @if ($label) role="status" aria-label="{{ $label }}" @else aria-hidden="true" focusable="false" @endif
>
    <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2.5" opacity="0.25" />
    <path
        d="M21 12a9 9 0 0 0-9-9"
        stroke="currentColor"
        stroke-width="2.5"
        stroke-linecap="round"
    />
</svg>
