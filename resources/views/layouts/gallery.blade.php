{{--
    layouts/gallery — envoltorio de la galería de componentes.

    NO es una pantalla del producto: es el BANCO DE PRUEBAS VISUAL del sistema
    de diseño. Renderiza todos los componentes en todos sus estados para poder
    capturarlos y revisarlos (lo usará darkware en la Fase 9).

    Se mantiene deliberadamente sobrio: cualquier adorno aquí competiría
    visualmente con los componentes que se están evaluando.

    Cómo se sirve hoy: `routes/` está FUERA de mi ámbito de escritura en la
    Fase 5a, así que no hay ruta registrada. La galería se renderiza a HTML
    estático con `php tools/render-gallery.php` y se sirve con
    `node tools/serve-static.mjs`. Ver el informe de fase.
--}}

@props(['title' => 'Galería de componentes'])

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · EncuestaLive</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-dvh bg-surface-canvas text-ink-900 antialiased">
    <x-skip-link target="contenido" />

    <header class="border-b border-ink-200 bg-white px-4 py-6 md:px-8">
        <div class="mx-auto w-full max-w-admin">
            <p class="text-sm font-semibold text-brand-700">EncuestaLive · Fase 5a</p>
            <h1 class="mt-1 text-3xl font-bold text-ink-900">{{ $title }}</h1>
            <p class="mt-2 max-w-prose text-base text-ink-600">
                Sistema de diseño derivado de <code class="font-mono text-sm">design-tokens.json</code>.
                Cada componente se muestra en todos los estados que la especificación le exige.
                Modo oscuro fuera del MVP por la decisión D-03.
            </p>
        </div>
    </header>

    <main id="contenido" class="px-4 py-8 md:px-8">
        <div class="mx-auto w-full max-w-admin">
            {{ $slot }}
        </div>
    </main>

    <footer class="border-t border-ink-200 px-4 py-6 text-sm text-ink-500 md:px-8">
        <div class="mx-auto w-full max-w-admin">
            Generado por <code class="font-mono">tools/render-gallery.php</code>.
        </div>
    </footer>
</body>
</html>
