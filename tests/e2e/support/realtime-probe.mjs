/*
 * Sonda de diagnostico (no es una prueba): observa TODAS las peticiones POST
 * que emite el dashboard y las clasifica, para construir el discriminador
 * WebSocket-vs-sondeo sobre datos observados y no supuestos.
 *
 * Dos cosas que esta sonda existe para resolver:
 *   1. El endpoint de Livewire esta OFUSCADO (`/livewire-<hash>/update`), asi
 *      que no se puede filtrar por la ruta canonica `/livewire/update`.
 *   2. La CSP de la Fase 7b es `script-src 'self'` SIN 'unsafe-eval'. El build
 *      normal de Livewire evalua las expresiones de `wire:poll` con
 *      `new Function()`. Hay que medir si el sondeo DISPARA o si muere ahi.
 *
 * Uso:  node tests/e2e/support/realtime-probe.mjs [segundos]
 */
import { chromium } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(here, '../../..');
const fx = JSON.parse(readFileSync(resolve(ROOT, 'storage/app/f6/fixture.json'), 'utf8'));
const survey = fx.surveys.active;
const OBSERVE_S = Number(process.argv[2] ?? 25);

const browser = await chromium.launch({ args: ['--no-sandbox', '--disable-dev-shm-usage'] });
const ctx = await browser.newContext({
    storageState: resolve(ROOT, 'storage/app/f6/auth-admin.json'),
    baseURL: 'http://127.0.0.1:8000',
    viewport: { width: 1280, height: 900 },
});
const page = await ctx.newPage();

const t0 = Date.now();
const posts = [];
const evalErrors = [];

page.on('websocket', (ws) => console.log('[ws] ', ws.url()));
page.on('console', (m) => {
    const t = m.text();
    if (/unsafe-eval|EvalError|Expression Error/i.test(t)) {
        evalErrors.push({ at: Date.now() - t0, text: t.slice(0, 200) });
    }
});

page.on('request', (req) => {
    if (req.method() !== 'POST') return;
    const body = req.postData() ?? '';
    let methods = [];
    let updates = [];
    try {
        const j = JSON.parse(body);
        for (const c of j.components ?? []) {
            methods.push(...(c.calls ?? []).map((k) => k.method));
            updates.push(...Object.keys(c.updates ?? {}));
        }
    } catch { /* no JSON */ }
    posts.push({ at: Date.now() - t0, url: new URL(req.url()).pathname, methods, updates });
});

await page.goto(`/admin/encuestas/${survey.id}/resultados`);
await page.waitForSelector('[data-dashboard]');

const dom = () => page.evaluate(() => {
    const el = document.querySelector('[data-dashboard]');
    return {
        poll: el.getAttributeNames().filter((n) => n.startsWith('wire:poll')),
        state: el.getAttribute('data-connection-state'),
        interval: el.getAttribute('data-poll-interval'),
    };
});

console.log('\n--- DOM inicial ---', JSON.stringify(await dom()));
console.log(`\n--- observando ${OBSERVE_S} s ---`);
await page.waitForTimeout(OBSERVE_S * 1000);
console.log('--- DOM final  ---', JSON.stringify(await dom()));

console.log('\n=== PETICIONES POST OBSERVADAS ===');
for (const p of posts) {
    console.log(`  +${String(p.at).padStart(6)} ms  ${p.url}  calls=${JSON.stringify(p.methods)} updates=${JSON.stringify(p.updates)}`);
}
console.log(`total POST: ${posts.length}`);

const refresh = posts.filter((p) => p.methods.includes('refreshStats'));
console.log(`\nllamadas a refreshStats (= SONDEO wire:poll): ${refresh.length}`);
console.log(`  -> con ${OBSERVE_S} s de observacion se esperarian ~${Math.floor(OBSERVE_S / 15)}-${Math.floor(OBSERVE_S / 5)} si el sondeo dispara`);
console.log(`llamadas a onStatsUpdated (= PUENTE Echo)   : ${posts.filter((p) => p.methods.includes('onStatsUpdated')).length}`);
console.log(`llamadas a setConnectionState               : ${posts.filter((p) => p.methods.includes('setConnectionState')).length}`);

console.log(`\n=== ERRORES DE EVALUACION (CSP) : ${evalErrors.length} ===`);
for (const e of evalErrors.slice(0, 6)) console.log(`  +${e.at} ms  ${e.text}`);

await browser.close();
process.exit(0);
