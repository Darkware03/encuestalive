#!/usr/bin/env node
/**
 * capture-sections.mjs — EncuestaLive, Fase 5a
 *
 * Captura la galería SECCIÓN POR SECCIÓN.
 *
 * Por qué existe además de capture-gallery.mjs: la captura de página completa
 * mide ~25 000 px de alto en escritorio y ~42 000 px en móvil. Sirve como
 * evidencia archivada, pero es inútil para REVISAR de verdad lo que se ha
 * construido: a esa escala no se distingue un borde de 2px de uno de 1px.
 * Estas capturas por sección son las que permiten mirar cada componente.
 *
 * Uso:  node tools/capture-sections.mjs
 * Salida: storage/app/screenshots/secciones/<seccion>-<ancho>.png
 */

import { chromium } from '@playwright/test';
import { spawn } from 'node:child_process';
import { mkdir } from 'node:fs/promises';
import { resolve, dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const PROJECT = resolve(__dirname, '..');
const OUT = join(PROJECT, 'storage/app/screenshots/secciones');

const WIDTHS = [
    { name: '390', width: 390, height: 844 },
    { name: '1280', width: 1280, height: 900 },
];

function startServer() {
    return new Promise((ok, fail) => {
        const proc = spawn(process.execPath, [join(__dirname, 'serve-static.mjs'), '0'], {
            stdio: ['ignore', 'pipe', 'pipe'],
        });
        const to = setTimeout(() => fail(new Error('timeout')), 10_000);
        proc.stdout.on('data', (d) => {
            const m = String(d).match(/LISTENING (\d+)/);
            if (m) { clearTimeout(to); ok({ proc, port: Number(m[1]) }); }
        });
        proc.on('exit', (c) => fail(new Error(`servidor salió con ${c}`)));
    });
}

const { proc, port } = await startServer();
await mkdir(OUT, { recursive: true });

const browser = await chromium.launch({ args: ['--no-sandbox', '--disable-dev-shm-usage'] });
let n = 0;

try {
    for (const vp of WIDTHS) {
        const ctx = await browser.newContext({
            viewport: { width: vp.width, height: vp.height },
            deviceScaleFactor: 1,
            locale: 'es-ES',
        });
        const page = await ctx.newPage();
        await page.goto(`http://127.0.0.1:${port}/`, { waitUntil: 'networkidle' });
        await page.evaluate(() => document.fonts.ready);

        const ids = await page.evaluate(() =>
            [...document.querySelectorAll('main section[id]')].map((s) => s.id)
        );

        for (const id of ids) {
            const el = page.locator(`#${id}`);
            await el.scrollIntoViewIfNeeded();
            await page.waitForTimeout(120);
            await el.screenshot({ path: join(OUT, `${id}-${vp.name}.png`) });
            n++;
        }
        await ctx.close();
    }
} finally {
    await browser.close();
    proc.kill();
}

console.log(`[secciones] ${n} capturas en ${OUT}`);
