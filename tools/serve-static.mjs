#!/usr/bin/env node
/**
 * serve-static.mjs — EncuestaLive, Fase 5a
 *
 * Servidor estático mínimo para poder CAPTURAR la galería con un navegador real.
 *
 * Sirve dos cosas:
 *   /                 → storage/app/screenshots/gallery.html
 *   /build/...        → public/build/...   (CSS, JS y los woff2 de Inter)
 *
 * No es parte de la aplicación: es utillaje de verificación visual. No se
 * registra ninguna ruta de Laravel porque `routes/` está fuera de mi ámbito de
 * escritura en esta fase.
 *
 * Se usa file:// en lugar de HTTP? No: con file:// el navegador aplica
 * restricciones de origen a las fuentes y a los módulos ES, y la captura
 * saldría sin Inter y sin JavaScript. Un servidor HTTP local evita ese falso
 * negativo.
 *
 * Uso:  node tools/serve-static.mjs [puerto]
 */

import { createServer } from 'node:http';
import { readFile, stat } from 'node:fs/promises';
import { resolve, extname, normalize, join } from 'node:path';
import { dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const PROJECT = resolve(__dirname, '..');
const PUBLIC = join(PROJECT, 'public');
const GALLERY = join(PROJECT, 'storage/app/screenshots/gallery.html');

const PORT = Number(process.argv[2] ?? 0);

const MIME = {
    '.html': 'text/html; charset=utf-8',
    '.css': 'text/css; charset=utf-8',
    '.js': 'text/javascript; charset=utf-8',
    '.mjs': 'text/javascript; charset=utf-8',
    '.json': 'application/json; charset=utf-8',
    '.woff2': 'font/woff2',
    '.woff': 'font/woff',
    '.svg': 'image/svg+xml',
    '.png': 'image/png',
    '.ico': 'image/x-icon',
};

const server = createServer(async (req, res) => {
    try {
        const url = new URL(req.url, 'http://localhost');
        let pathname = decodeURIComponent(url.pathname);

        if (pathname === '/' || pathname === '/gallery.html') {
            const body = await readFile(GALLERY);
            res.writeHead(200, { 'content-type': MIME['.html'] });
            res.end(body);
            return;
        }

        // Evita salir de public/ con ../
        const safe = normalize(pathname).replace(/^(\.\.[/\\])+/, '');
        const file = join(PUBLIC, safe);
        if (!file.startsWith(PUBLIC)) {
            res.writeHead(403).end('Forbidden');
            return;
        }

        await stat(file);
        const body = await readFile(file);
        res.writeHead(200, {
            'content-type': MIME[extname(file)] ?? 'application/octet-stream',
            'cache-control': 'no-store',
        });
        res.end(body);
    } catch {
        res.writeHead(404, { 'content-type': 'text/plain; charset=utf-8' });
        res.end('404');
    }
});

server.listen(PORT, '127.0.0.1', () => {
    const { port } = server.address();
    // Línea que lee capture-gallery.mjs para descubrir el puerto
    console.log(`LISTENING ${port}`);
});
