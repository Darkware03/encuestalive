@props([
    'label' => 'Cargando…',
])

{{--
    skeleton-group — contenedor accesible de bloques x-skeleton (§6.12 · 16.b).

    El contenedor lleva aria-busy="true" y aria-live="polite" con el texto
    oculto «Cargando…». Los bloques ▒ individuales son aria-hidden="true"
    (los marca cada x-skeleton). Al terminar la carga se retira aria-busy y
    se anuncia «Resultados cargados» — eso lo hará el componente Livewire
    correspondiente en la Fase 5b.
--}}

<div {{ $attributes }} aria-busy="true" aria-live="polite">
    <span class="sr-only">{{ $label }}</span>
    {{ $slot }}
</div>
