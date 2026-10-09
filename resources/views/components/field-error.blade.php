@props([
    'id' => null,
    'message' => null,
])

{{--
    field-error — mensaje de error de un campo (§6.2 · wireframes-states 17.a).

    Regla WCAG 1.4.1 / 3.3.1: ICONO + COLOR + TEXTO. Nunca solo color.
    Regla WCAG 3.3.3: el mensaje dice CÓMO corregir, no solo que hay un fallo.
    El campo asociado lleva aria-invalid="true" y aria-describedby a este id.
--}}

@if ($message || ! $slot->isEmpty())
    <p
        @if ($id) id="{{ $id }}" @endif
        {{ $attributes->class('mt-1 flex items-start gap-1.5 text-sm text-danger-700') }}
    >
        <x-icon name="exclamation-triangle" :size="16" class="mt-0.5" />
        <span>{{ $message ?? $slot }}</span>
    </p>
@endif
