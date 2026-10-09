@props([
    /* success | info | warning | error */
    'tone' => 'success',
    'title' => null,
    /*
     * Desactiva el autocierre SIN cambiar el resto del comportamiento.
     *
     * DEFECTO CORREGIDO TRAS VER EL RENDER (Fase 5a): en la primera captura
     * de la galería faltaba el toast de éxito. No estaba roto — se había
     * autocerrado a los 4 s, antes de que la cámara llegase a esa sección.
     * Un banco de pruebas que borra sus propias piezas no sirve para revisar,
     * así que la galería los fija con :persist. En producción NO se usa:
     * los tiempos reales (4/5/8 s y el error sin autocierre) siguen vigentes.
     */
    'persist' => false,
])

{{--
    toast — §6.10

    Duraciones y roles (los aplica resources/js/app.js → initToasts):
      éxito       4 s · autocierre · role="status"
      información 5 s · autocierre · role="status"
      advertencia 8 s ·            · role="status"
      ERROR       SIN AUTOCIERRE   · role="alert"   ← WCAG 2.2.1

    Reglas:
      · un error NUNCA desaparece solo
      · máximo 3 simultáneos, el resto en cola (lo gestiona el JS)
      · botón de cerrar siempre presente, 44×44px (§9.5)
      · al pasar el ratón o enfocar, el temporizador se pausa
      · un toast NUNCA es el único medio de comunicar un error de formulario:
        el error vive también junto al campo (x-field-error)
--}}

@php
    $tones = [
        'success' => ['border' => 'border-l-success-700', 'icon' => 'check-circle', 'fg' => 'text-success-700'],
        'info' => ['border' => 'border-l-info-700', 'icon' => 'information-circle', 'fg' => 'text-info-700'],
        'warning' => ['border' => 'border-l-warning-800', 'icon' => 'exclamation-triangle', 'fg' => 'text-warning-800'],
        'error' => ['border' => 'border-l-danger-700', 'icon' => 'x-circle', 'fg' => 'text-danger-700'],
    ];

    $t = $tones[$tone] ?? $tones['success'];
    $role = $tone === 'error' ? 'alert' : 'status';
@endphp

<div
    {{ $attributes->class(
        'pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-lg border border-ink-200 '
        . 'border-l-[3px] bg-white p-4 shadow-lg ' . $t['border']
    ) }}
    data-toast="{{ $tone }}"
    @if ($persist) data-toast-persist @endif
    role="{{ $role }}"
    @if ($tone !== 'error') aria-live="polite" @endif
>
    <span class="mt-0.5 {{ $t['fg'] }}">
        <x-icon :name="$t['icon']" :size="20" />
    </span>

    <div class="min-w-0 flex-1">
        @if ($title)
            <p class="text-base font-semibold text-ink-900">{{ $title }}</p>
        @endif
        <p class="text-sm text-ink-600" data-user-text>{{ $slot }}</p>
    </div>

    {{-- 44×44 de área táctil aunque el aspa dibuje 20px (§9.5) --}}
    <button
        type="button"
        class="-m-2 inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-md text-ink-700
               transition-colors hover:bg-ink-100
               focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-700"
        data-toast-close
        aria-label="Cerrar aviso"
    >
        <x-icon name="x-mark" :size="20" />
    </button>
</div>
