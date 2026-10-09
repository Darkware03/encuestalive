@props([
    'title' => null,
    /* [['label' => 'Título', 'message' => 'escribe un título', 'anchor' => 'titulo'], ...] */
    'errors' => [],
])

{{--
    error-summary — resumen de errores (wireframes-states 17.b).

    Se usa en formularios de MÁS DE 3 CAMPOS. Reglas del wireframe:
      · role="alert" + tabindex="-1": al aparecer, el foco salta aquí
      · CADA PUNTO ES UN ENLACE que lleva el foco al campo correspondiente
      · el mensaje dice CÓMO corregir, no solo que falta algo (WCAG 3.3.3)

    No sustituye al error junto al campo: lo acompaña. Un error que solo
    aparece en el resumen obliga a buscar el campo a ojo.
--}}

@php
    $count = count($errors);
@endphp

@if ($count)
    <div
        {{ $attributes->class('rounded-md border border-danger-700 bg-danger-50 p-4') }}
        role="alert"
        tabindex="-1"
    >
        <p class="flex items-start gap-2 text-base font-semibold text-danger-700">
            <x-icon name="exclamation-triangle" :size="20" class="mt-0.5" />
            <span>{{ $title ?? 'No pudimos guardar. Revisa ' . $count . ' ' . ($count === 1 ? 'campo' : 'campos') . ':' }}</span>
        </p>

        <ul class="mt-3 ml-9 list-disc space-y-1 text-sm text-ink-800 marker:text-danger-700">
            @foreach ($errors as $e)
                <li>
                    <a
                        href="#{{ $e['anchor'] ?? '' }}"
                        class="font-semibold text-brand-700 underline underline-offset-2
                               hover:text-brand-800
                               focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-700"
                    >{{ $e['label'] ?? '' }}</a>
                    — {{ $e['message'] ?? '' }}
                </li>
            @endforeach
        </ul>
    </div>
@endif
