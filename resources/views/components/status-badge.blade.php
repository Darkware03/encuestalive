@props([
    /* draft | scheduled | active | paused | closed | archived */
    'status' => 'draft',
])

{{--
    status-badge — badge de estado de encuesta (§6.7). Los SEIS estados.

    REGLA: SIEMPRE incluye la palabra. Nunca un punto de color a secas
    (WCAG 1.4.1: el color no puede ser el único portador de significado).

    Estados interactivos: NO APLICAN. Un badge es informativo, no accionable.
    Si necesitara ser clicable sería un filtro, y entonces es un x-button.

    Los pares bg/fg salen de design-tokens.json → color.surveyStatus y están
    verificados en §6.7 (contrastes de 5,47:1 a 9,24:1, todos ≥ 4,5:1).
--}}

@php
    $labels = [
        'draft' => 'Borrador',
        'scheduled' => 'Programada',
        'active' => 'Activa',
        'paused' => 'Pausada',
        'closed' => 'Cerrada',
        'archived' => 'Archivada',
    ];

    $classes = [
        'draft' => 'bg-status-draft-bg text-status-draft-fg',
        'scheduled' => 'bg-status-scheduled-bg text-status-scheduled-fg',
        'active' => 'bg-status-active-bg text-status-active-fg',
        'paused' => 'bg-status-paused-bg text-status-paused-fg',
        'closed' => 'bg-status-closed-bg text-status-closed-fg',
        'archived' => 'bg-status-archived-bg text-status-archived-fg',
    ];

    $key = array_key_exists($status, $labels) ? $status : 'draft';
@endphp

<span {{ $attributes->class('inline-flex items-center rounded-sm px-2 py-0.5 text-xs font-semibold ' . $classes[$key]) }}>
    {{ $slot->isEmpty() ? $labels[$key] : $slot }}
</span>
