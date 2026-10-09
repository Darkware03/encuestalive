<?php

/**
 * render-gallery.php — EncuestaLive, Fase 5a
 *
 * Renderiza la galería de componentes a HTML ESTÁTICO.
 *
 * POR QUÉ UN SCRIPT Y NO UNA RUTA:
 * `routes/` está fuera de mi ámbito de escritura en esta fase (y
 * `database-engineer` trabaja en paralelo sobre el mismo repositorio), así que
 * no registro ninguna ruta. Este script arranca Laravel, compila el Blade y
 * escribe el resultado donde sí puedo escribir. En cuanto exista una ruta
 * `/dev/galeria`, la galería se sirve directamente y este script deja de ser
 * necesario. Ver el informe de fase.
 *
 * Uso:  php tools/render-gallery.php
 * Salida: storage/app/screenshots/gallery.html
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require $root . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$manifest = $root . '/public/build/manifest.json';
if (! is_file($manifest)) {
    fwrite(STDERR, "[galeria] Falta public/build/manifest.json. Ejecuta antes: npm run build\n");
    exit(1);
}

$inner = Blade::render('<x-gallery />');

$html = view('layouts.gallery', [
    'slot' => new HtmlString($inner),
])->render();

/*
 * @vite emite URLs absolutas basadas en APP_URL (p. ej. http://localhost/build/...).
 * El HTML se sirve desde tools/serve-static.mjs en un puerto efímero, así que
 * se reescriben a rutas relativas de raíz. No se toca nada más del marcado.
 */
$html = preg_replace('#https?://[^"\']*?/build/#', '/build/', $html);

$outDir = $root . '/storage/app/screenshots';
if (! is_dir($outDir)) {
    mkdir($outDir, 0o775, true);
}

$outFile = $outDir . '/gallery.html';
file_put_contents($outFile, $html);

printf(
    "[galeria] Escrito %s (%d KB)\n",
    $outFile,
    (int) round(strlen($html) / 1024)
);
