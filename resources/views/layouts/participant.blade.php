@props([
    /* §9.1: el <title> es único y descriptivo. En P8 incluye
       «Pregunta 2 de 3 — <encuesta>». */
    'title' => 'Participar',
    'connection' => 'online',
    'connectionDetail' => null,
    /* Mostrar la flecha «‹ Atrás» de la cabecera (8.b) */
    'back' => null,
    /* Texto «1 de 3» de la cabecera */
    'step' => null,
])

{{--
    layouts/participant — zona pública (UX_UI_SPEC §3.1, §3.3, §10).

    PENSADO PARA UN TELÉFONO CON MALA CONEXIÓN EN UN EVENTO:

    · SIN NAVEGACIÓN. Ni menú, ni enlace al inicio, ni buscador. Decisión de
      UX (cero distracción) y de seguridad: un participante no debe poder
      descubrir otras encuestas ni la zona admin (§3.1).
    · El contenido NUNCA supera 32rem (512px). En escritorio gana aire, no
      anchura: la longitud de línea se mantiene legible (§5.8).
    · Mobile-first REAL: el estilo base está escrito para 360px y no lleva
      prefijo; los prefijos solo amplían.
    · El HTML llega RENDERIZADO desde el servidor. El participante debe poder
      leer y marcar sin esperar a JavaScript (16.d). Por eso el <script> de
      Vite va con defer (lo hace @vite) y nada esencial depende de él.
    · Acción primaria FIJA ABAJO, ancho completo, 48px de alto (§9.5, §10).
    · scroll-padding-bottom global para que esa barra fija no tape el foco
      al tabular (WCAG 2.2 §2.4.11, riesgo A3).
--}}

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    {{-- Sin maximum-scale ni user-scalable=no (§10 regla 4 · WCAG 1.4.4) --}}
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · EncuestaLive</title>

    {{-- El QR y la página pública no deben indexarse --}}
    <meta name="robots" content="noindex, nofollow">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-dvh flex-col bg-surface-canvas text-ink-900 antialiased">

    <x-skip-link target="contenido" />

    {{-- Cabecera de 48px: marca discreta + estado de conexión (8.a) --}}
    <header class="sticky top-0 border-b border-ink-200 bg-white" style="z-index: var(--z-sticky)">
        <div class="mx-auto flex h-12 w-full max-w-participant items-center gap-3 px-4">
            @if ($back)
                <a
                    href="{{ $back }}"
                    class="-ml-2 inline-flex h-11 items-center gap-1 rounded-md px-2 text-sm font-medium text-brand-700
                           hover:bg-ink-100 focus-visible:outline-2 focus-visible:outline-offset-2
                           focus-visible:outline-brand-700"
                >
                    <x-icon name="chevron-left" :size="20" />
                    Atrás
                </a>
            @else
                <span class="text-base font-bold text-brand-700">EncuestaLive</span>
            @endif

            @if ($step)
                <span class="flex-1 text-center text-sm tabular text-ink-600">{{ $step }}</span>
            @else
                <span class="flex-1"></span>
            @endif

            {{-- Región role="status" separada. No es enfocable: es informativa
                 y no entra en el orden de tabulación de §9.2. --}}
            <x-connection-indicator :state="$connection" :detail="$connectionDetail" class="shrink-0" />
        </div>

        @isset($progress)
            <div class="mx-auto w-full max-w-participant px-4 pb-3">
                {{ $progress }}
            </div>
        @endisset
    </header>

    {{-- Banda de desconexión. El contenido de la pregunta SIGUE SIENDO
         USABLE y marcable por debajo (18.b): no se bloquea nada. --}}
    @isset($statusBanner)
        {{ $statusBanner }}
    @endisset

    <main id="contenido" class="w-full flex-1 px-4 py-6">
        <div class="mx-auto w-full max-w-participant">
            {{ $slot }}
        </div>
    </main>

    {{-- Acción primaria fija abajo, ancho completo, 48px (§10) --}}
    @isset($actionBar)
        <div
            class="el-action-bar sticky bottom-0 border-t border-ink-200 bg-white px-4 pt-4"
            style="z-index: var(--z-sticky)"
        >
            <div class="mx-auto w-full max-w-participant">
                {{ $actionBar }}
            </div>
        </div>
    @endisset

    <footer class="px-4 py-6 text-center text-xs text-ink-500">
        <div class="mx-auto w-full max-w-participant">
            <a
                href="/privacidad"
                class="rounded-sm text-brand-700 underline underline-offset-2 hover:text-brand-800
                       focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-700"
            >Cómo tratamos los datos</a>
        </div>
    </footer>

    <x-toast-region>
        {{ $toasts ?? '' }}
    </x-toast-region>
</body>
</html>
