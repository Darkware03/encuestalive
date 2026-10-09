@props([
    'label',
    'value',
    /* Nota bajo la cifra: «de 12», «ahora», «últ. 24h», «media» */
    'note' => null,
    /* true → el dato es el último conocido y la conexión está caída (18.a):
       se atenúa al 70 % y se declara la antigüedad. NUNCA se pone a cero. */
    'stale' => false,
    'staleNote' => null,
])

{{--
    stat — tarjeta de KPI (wireframes-admin P2 · P10).

    Marcado: <article> con <h3> visible. La cifra usa tabular-nums para que
    al pasar de 99 a 100 no cambie de ancho y el diseño no «salte» (§5.3 regla 2).

    DATOS OBSOLETOS (18.a): al perder la conexión el valor NO se vacía ni se
    pone a 0 — eso sería comunicar un dato falso. Se mantiene el último valor
    conocido, atenuado, con la antigüedad declarada.

    Los números de esta galería son de MUESTRA. En la Fase 5b vendrán de
    StatsService, única fuente autorizada de estas métricas (D-07).
--}}

<article {{ $attributes->class('rounded-lg border border-ink-200 bg-white p-4 md:p-6') }}>
    <h3 class="text-sm font-semibold text-ink-600">{{ $label }}</h3>

    <p class="mt-2 text-4xl font-bold tabular {{ $stale ? 'text-ink-900/70' : 'text-ink-900' }}">
        {{ $value }}
    </p>

    @if ($stale && $staleNote)
        <p class="mt-1 text-xs font-medium text-connection-stale">{{ $staleNote }}</p>
    @elseif ($note)
        <p class="mt-1 text-xs text-ink-500">{{ $note }}</p>
    @endif
</article>
