#!/usr/bin/env node
/**
 * capture-gallery.mjs — EncuestaLive, Fase 5a
 *
 * VERIFICACIÓN VISUAL REAL de la galería de componentes con Chromium.
 *
 * Levanta tools/serve-static.mjs en un puerto efímero, abre la galería con
 * Playwright y captura PNG en los anchos exigidos. Además recoge pruebas que
 * una captura por sí sola no demuestra:
 *   · errores de consola y peticiones fallidas (una fuente que no carga o un
 *     JS roto no siempre se ven en la foto),
 *   · si Inter está REALMENTE aplicada (document.fonts.check),
 *   · si hay scroll horizontal a 320/360/390px (§10 regla 1: prohibido),
 *   · alturas reales de los objetivos táctiles (§9.5: 44px admin, 48px
 *     participante).
 *
 * Chromium se lanza con --no-sandbox y --disable-dev-shm-usage: no hay root
 * en este host. Los avisos de D-Bus son ruido no fatal.
 *
 * Uso:  node tools/capture-gallery.mjs
 * Salida: storage/app/screenshots/*.png  +  capture-report.json
 */

import { chromium } from '@playwright/test';
import { spawn } from 'node:child_process';
import { mkdir, writeFile } from 'node:fs/promises';
import { resolve, dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const PROJECT = resolve(__dirname, '..');
const OUT = join(PROJECT, 'storage/app/screenshots');

/* Anchos exigidos por la tarea + los de control del reflujo (§9.6 / §10) */
const VIEWPORTS = [
    { name: 'movil-390', width: 390, height: 844, label: 'Móvil 390px' },
    { name: 'escritorio-1280', width: 1280, height: 900, label: 'Escritorio 1280px' },
    { name: 'reflujo-320', width: 320, height: 800, label: 'Reflujo 320px (WCAG 1.4.10)' },
    { name: 'tablet-768', width: 768, height: 1024, label: 'Tablet 768px' },
];

/* ---------------------------------------------------------------- servidor */

function startServer() {
    return new Promise((ok, fail) => {
        const proc = spawn(process.execPath, [join(__dirname, 'serve-static.mjs'), '0'], {
            stdio: ['ignore', 'pipe', 'pipe'],
        });
        const to = setTimeout(() => fail(new Error('El servidor no arrancó en 10 s')), 10_000);
        proc.stdout.on('data', (d) => {
            const m = String(d).match(/LISTENING (\d+)/);
            if (m) {
                clearTimeout(to);
                ok({ proc, port: Number(m[1]) });
            }
        });
        proc.stderr.on('data', (d) => process.stderr.write(`[servidor] ${d}`));
        proc.on('exit', (c) => fail(new Error(`El servidor terminó con código ${c}`)));
    });
}

/* ------------------------------------------------------------------ main */

const report = {
    generatedAt: new Date().toISOString(),
    screenshots: [],
    consoleErrors: [],
    failedRequests: [],
    checks: {},
};

const { proc, port } = await startServer();
const base = `http://127.0.0.1:${port}/`;
await mkdir(OUT, { recursive: true });

const browser = await chromium.launch({
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
});

try {
    for (const vp of VIEWPORTS) {
        const ctx = await browser.newContext({
            viewport: { width: vp.width, height: vp.height },
            deviceScaleFactor: 2,
            locale: 'es-ES',
        });
        const page = await ctx.newPage();

        page.on('console', (m) => {
            if (m.type() === 'error') {
                report.consoleErrors.push({ viewport: vp.name, text: m.text() });
            }
        });
        page.on('requestfailed', (r) => {
            report.failedRequests.push({
                viewport: vp.name,
                url: r.url(),
                reason: r.failure()?.errorText,
            });
        });

        await page.goto(base, { waitUntil: 'networkidle' });
        await page.evaluate(() => document.fonts.ready);

        /* --- ¿Inter está realmente aplicada? ------------------------------ */
        const fontCheck = await page.evaluate(() => ({
            interLoaded: document.fonts.check('16px Inter'),
            bodyFamily: getComputedStyle(document.body).fontFamily,
            loadedFaces: [...document.fonts]
                .filter((f) => f.status === 'loaded')
                .map((f) => `${f.family} ${f.weight}`),
        }));

        /* --- §10 regla 1: NUNCA scroll horizontal ------------------------- */
        const overflow = await page.evaluate(() => {
            const docW = document.documentElement.clientWidth;
            const offenders = [];
            for (const el of document.querySelectorAll('*')) {
                const r = el.getBoundingClientRect();
                if (r.width > 0 && (r.right > docW + 1 || r.left < -1)) {
                    offenders.push({
                        tag: el.tagName.toLowerCase(),
                        cls: (el.className?.baseVal ?? el.className ?? '').toString().slice(0, 90),
                        right: Math.round(r.right),
                        left: Math.round(r.left),
                    });
                }
            }
            return {
                scrollWidth: document.documentElement.scrollWidth,
                clientWidth: docW,
                hasHorizontalScroll: document.documentElement.scrollWidth > docW + 1,
                offenders: offenders.slice(0, 12),
            };
        });

        /* --- §9.5: objetivos táctiles ------------------------------------- */
        const targets = await page.evaluate(() => {
            const small = [];
            const sel = 'button, a[href], input:not([type=hidden]), select, textarea, label.el-option-row';

            for (const el of document.querySelectorAll(sel)) {
                if (el.closest('[hidden]')) continue;

                /*
                 * El objetivo real de un radio/checkbox NO es el cuadro de 20px:
                 * es el <label> que lo envuelve, que ocupa la fila entera. Medir
                 * el input daba un falso positivo de 14 controles «de 20×20»
                 * cuando la zona pulsable es de 52px de alto y ancho completo.
                 */
                const target = el.closest('label') ?? el;
                const r = target.getBoundingClientRect();

                if (r.width === 0 && r.height === 0) continue; // oculto

                /*
                 * Excepción «inline» de WCAG 2.2 §2.5.8: un enlace dentro de una
                 * frase queda exento, porque su tamaño lo fija el interlineado
                 * del texto que lo rodea. Se detecta por tener texto hermano.
                 */
                const inlineInSentence =
                    el.tagName === 'A' &&
                    !!el.parentElement &&
                    (el.parentElement.textContent ?? '').trim() !== (el.textContent ?? '').trim();

                if (inlineInSentence) continue;

                if (r.height < 24 || r.width < 24) {
                    small.push({
                        tag: el.tagName.toLowerCase(),
                        text: (el.textContent ?? '').trim().slice(0, 40),
                        w: Math.round(r.width),
                        h: Math.round(r.height),
                    });
                }
            }

            const rows = [...document.querySelectorAll('label.el-option-row')]
                .filter((e) => !e.closest('[hidden]'))
                .map((e) => Math.round(e.getBoundingClientRect().height));

            return {
                belowWcagMinimum: small,
                optionRowHeights: [...new Set(rows)].sort((a, b) => a - b),
            };
        });

        report.checks[vp.name] = { font: fontCheck, overflow, targets };

        /* --- captura de página completa ----------------------------------- */
        const file = join(OUT, `galeria-${vp.name}.png`);
        await page.screenshot({ path: file, fullPage: true });
        report.screenshots.push({ viewport: vp.name, label: vp.label, file });

        /* --- modales: hay que ABRIRLOS para poder fotografiarlos ----------- */
        if (vp.name === 'movil-390' || vp.name === 'escritorio-1280') {
            for (const [modalId, suffix] of [
                ['g-modal-export', 'modal-exportar'],
                ['g-modal-delete', 'modal-destructivo'],
            ]) {
                await page.evaluate((id) => window.EncuestaLive.openModal(document.getElementById(id)), modalId);
                await page.waitForTimeout(250);
                const mf = join(OUT, `${suffix}-${vp.name}.png`);
                await page.screenshot({ path: mf });
                report.screenshots.push({ viewport: vp.name, label: `${vp.label} · ${suffix}`, file: mf });
                await page.evaluate((id) => window.EncuestaLive.closeModal(document.getElementById(id)), modalId);
                await page.waitForTimeout(150);
            }

            /* --- foco real con teclado: se tabula y se fotografía el anillo --- */
            await page.evaluate(() => window.scrollTo(0, 0));
            for (let i = 0; i < 3; i++) await page.keyboard.press('Tab');
            await page.waitForTimeout(120);
            const ff = join(OUT, `foco-teclado-${vp.name}.png`);
            await page.screenshot({ path: ff });
            report.screenshots.push({ viewport: vp.name, label: `${vp.label} · foco con teclado`, file: ff });
            report.checks[vp.name].focusedAfter3Tabs = await page.evaluate(() => {
                const a = document.activeElement;
                return a ? `${a.tagName.toLowerCase()} · ${(a.textContent ?? '').trim().slice(0, 40)}` : null;
            });
        }

        await ctx.close();
    }

    /* --- prefers-reduced-motion: el skeleton debe quedar PLANO ------------ */
    {
        const ctx = await browser.newContext({
            viewport: { width: 1280, height: 900 },
            deviceScaleFactor: 2,
            reducedMotion: 'reduce',
            locale: 'es-ES',
        });
        const page = await ctx.newPage();
        await page.goto(base, { waitUntil: 'networkidle' });
        await page.evaluate(() => document.fonts.ready);
        await page.evaluate(() => document.getElementById('skeleton')?.scrollIntoView());
        await page.waitForTimeout(300);
        const f = join(OUT, 'reduced-motion-1280.png');
        await page.screenshot({ path: f });
        report.screenshots.push({ viewport: 'reduced-motion', label: 'prefers-reduced-motion: reduce', file: f });
        report.checks.reducedMotion = await page.evaluate(() => {
            const s = document.querySelector('.el-skeleton');
            if (!s) return null;
            const cs = getComputedStyle(s);
            return {
                animationName: cs.animationName,
                backgroundImage: cs.backgroundImage.slice(0, 40),
                flatAsRequired: cs.animationName === 'none' && cs.backgroundImage === 'none',
            };
        });
        await ctx.close();
    }
} finally {
    await browser.close();
    proc.kill();
}

await writeFile(join(OUT, 'capture-report.json'), JSON.stringify(report, null, 2));

/* ------------------------------------------------------------- resumen */
console.log(`\n[captura] ${report.screenshots.length} PNG en ${OUT}`);
for (const s of report.screenshots) console.log(`  · ${s.label} → ${s.file.split('/').pop()}`);

console.log(`\n[captura] Errores de consola: ${report.consoleErrors.length}`);
for (const e of report.consoleErrors) console.log(`  ! ${e.viewport}: ${e.text}`);

console.log(`[captura] Peticiones fallidas: ${report.failedRequests.length}`);
for (const r of report.failedRequests) console.log(`  ! ${r.viewport}: ${r.url} (${r.reason})`);

for (const [name, c] of Object.entries(report.checks)) {
    if (name === 'reducedMotion') continue;
    console.log(`\n[${name}]`);
    console.log(`  Inter aplicada: ${c.font.interLoaded} · ${c.font.loadedFaces.length} caras cargadas`);
    console.log(`  Scroll horizontal: ${c.overflow.hasHorizontalScroll} (scrollW ${c.overflow.scrollWidth} vs clientW ${c.overflow.clientWidth})`);
    if (c.overflow.offenders.length) {
        for (const o of c.overflow.offenders) console.log(`    ! desborda: <${o.tag}> ${o.cls} [${o.left}..${o.right}]`);
    }
    console.log(`  Alturas de fila de opción: ${c.targets.optionRowHeights.join(', ')}px`);
    console.log(`  Objetivos < 24px: ${c.targets.belowWcagMinimum.length}`);
    for (const t of c.targets.belowWcagMinimum.slice(0, 8)) {
        console.log(`    ! <${t.tag}> ${t.w}×${t.h} "${t.text}"`);
    }
    if (c.focusedAfter3Tabs) console.log(`  Foco tras 3 tabulaciones: ${c.focusedAfter3Tabs}`);
}

console.log(`\n[captura] reduced-motion → ${JSON.stringify(report.checks.reducedMotion)}`);
