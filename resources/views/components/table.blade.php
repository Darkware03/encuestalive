@props([
    /* <caption> obligatorio (§9.3). Se oculta visualmente, no se omite. */
    'caption',
    /* [['label' => 'Encuesta', 'sort' => 'ascending'|'descending'|'none'|null], ...] */
    'columns' => [],
    'error' => null,
    'loading' => false,
    'skeletonRows' => 5,
])

{{--
    table — §6.8

    <table> real con <caption> oculto, <th scope="col"> y aria-sort en la
    columna ordenada. Cabecera ink-100, filas separadas por ink-200.
    Zebra striping: NO (ruido; el brief pide minimalismo).

    RESPONSIVE — REGLA DURA: nada de scroll horizontal. Por debajo de `md`
    esta tabla se OCULTA y en su lugar se muestra la lista de tarjetas que
    el consumidor pasa por el slot `cards`. Las dos representaciones deben
    contener los mismos datos; la oculta lleva aria-hidden para que el lector
    de pantalla no lea la tabla dos veces.
--}}

<div {{ $attributes->only('class')->class('w-full') }}>
    @if ($error)
        {{-- Banda sobre la tabla; los datos antiguos SIGUEN visibles (§6.8) --}}
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3 rounded-md border border-danger-700 bg-danger-50 px-4 py-3" role="alert">
            <p class="flex items-start gap-2 text-sm text-danger-700">
                <x-icon name="exclamation-triangle" :size="16" class="mt-0.5" />
                <span>{{ $error }}</span>
            </p>
            <x-button variant="outline" size="sm" icon="arrow-path">Reintentar</x-button>
        </div>
    @endif

    {{-- ≥ md: tabla real --}}
    <div class="hidden md:block" @if ($loading) aria-busy="true" @endif>
        <table class="w-full border-collapse text-left">
            <caption class="sr-only">{{ $caption }}</caption>
            <thead>
                <tr class="bg-ink-100">
                    @foreach ($columns as $col)
                        @php
                            $label = is_array($col) ? ($col['label'] ?? '') : $col;
                            $sort = is_array($col) ? ($col['sort'] ?? null) : null;
                            $numeric = is_array($col) ? ($col['numeric'] ?? false) : false;
                        @endphp
                        <th
                            scope="col"
                            class="px-4 py-3 text-sm font-semibold text-ink-700 {{ $numeric ? 'text-right' : '' }}"
                            @if ($sort) aria-sort="{{ $sort }}" @endif
                        >
                            {{ $label }}
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-ink-200">
                @if ($loading)
                    @for ($r = 0; $r < $skeletonRows; $r++)
                        {{-- Skeleton con el NÚMERO REAL de columnas (§6.8) --}}
                        <tr aria-hidden="true">
                            @foreach ($columns as $i => $col)
                                <td class="px-4 py-4" style="height: 52px">
                                    <span class="el-skeleton block h-4 rounded-sm" style="width: {{ $i === 0 ? '70%' : '45%' }}"></span>
                                </td>
                            @endforeach
                        </tr>
                    @endfor
                    <tr class="sr-only"><td colspan="{{ max(1, count($columns)) }}">Cargando resultados…</td></tr>
                @else
                    {{ $slot }}
                @endif
            </tbody>
        </table>
    </div>

    {{--
        < md: la tabla se TRANSFORMA en lista de tarjetas. Nunca scroll lateral.

        DEFECTO CORREGIDO TRAS VER EL RENDER (Fase 5a): la captura a 390px
        mostraba un RECUADRO VACÍO en el estado `loading`. La tabla real vive
        en `hidden md:block`, así que en móvil se ocultaba junto con sus filas
        de skeleton y no quedaba ninguna señal de carga. Ahora el móvil tiene
        su propio skeleton con forma de tarjeta.
    --}}
    <div class="md:hidden">
        @if ($loading)
            <x-skeleton-group label="Cargando…">
                <ul class="flex flex-col gap-4">
                    @for ($r = 0; $r < 3; $r++)
                        <li class="rounded-lg border border-ink-200 bg-white p-4">
                            <x-skeleton shape="text" width="65%" />
                            <div class="mt-2"><x-skeleton shape="text" width="35%" /></div>
                            <div class="mt-4"><x-skeleton shape="block" height="44px" /></div>
                        </li>
                    @endfor
                </ul>
            </x-skeleton-group>
        @elseif (isset($cards))
            {{ $cards }}
        @endif
    </div>
</div>
