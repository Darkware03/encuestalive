/*
 * Sonda del RESPALDO en modo degradado, con ventana larga.
 *
 * En la sonda corta, con Reverb caido, el contador subio... pero NO por
 * `refreshStats` (el sondeo) sino por un `setConnectionState` que dispara
 * Echo al reintentar la conexion. Cualquier ida y vuelta a Livewire
 * re-renderiza el componente y este relee StatsService, asi que el refresco
 * parecia funcionar por accidente.
 *
 * pusher-js reintenta con ESPERA CRECIENTE. Esta sonda deja pasar un rato
 * largo para que la espera crezca y luego mide si el dashboard sigue vivo.
 *
 * Uso:  node tests/e2e/support/degraded-probe.mjs [espera_s] [observacion_s]
 */
import { chromium, request as pwRequest } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(here, '../../..');
const fx = JSON.parse(readFileSync(resolve(ROOT, 'storage/app/f6/fixture.json'), 'utf8'));
const survey = fx.surveys.active;
const SETTLE_S = Number(process.argv[2] ?? 75);
const WATCH_S = Number(process.argv[3] ?? 60);
const BASE = 'http://127.0.0.1:8000';
const LW = /\/livewire[^/]*\/update$/;

const browser = await chromium.launch({ args: ['--no-sandbox', '--disable-dev-shm-usage'] });
const ctx = await browser.newContext({
    storageState: resolve(ROOT, 'storage/app/f6/auth-admin.json'),
    baseURL: BASE,
    viewport: { width: 1280, height: 900 },
});
const page = await ctx.newPage();

const t0 = Date.now();
const posts = [];
let evalErrors = 0;
page.on('console', (m) => { if (/unsafe-eval|EvalError/i.test(m.text())) evalErrors++; });
page.on('request', (req) => {
    if (req.method() !== 'POST' || !LW.test(new URL(req.url()).pathname)) return;
    let methods = [];
    try {
        for (const c of (JSON.parse(req.postData() ?? '{}').components ?? [])) {
            methods.push(...(c.calls ?? []).map((k) => k.method));
        }
    } catch { /* ignora */ }
    posts.push({ at: Date.now() - t0, methods });
});

await page.goto(`/admin/encuestas/${survey.id}/resultados`);
await page.waitForSelector('[data-dashboard]');

const counter = page.locator('article')
    .filter({ has: page.getByRole('heading', { level: 3, name: 'Respuestas completadas' }) })
    .locator('p').first();

console.log(`\n### RESPALDO DEGRADADO · espera ${SETTLE_S}s, observacion ${WATCH_S}s`);
console.log(`dejando pasar ${SETTLE_S} s para que crezca la espera de reintento de Echo...`);
await page.waitForTimeout(SETTLE_S * 1000);

const dom = await page.evaluate(() => {
    const el = document.querySelector('[data-dashboard]');
    return {
        poll: el.getAttributeNames().filter((n) => n.startsWith('wire:poll')),
        state: el.getAttribute('data-connection-state'),
    };
});
console.log(`DOM: poll=${JSON.stringify(dom.poll)} estado=${dom.state}`);
console.log(`POST en la fase de espera: ${posts.length}  ${JSON.stringify(posts.map((p) => p.at + 'ms:' + p.methods.join('|')))}`);

const before = Number((await counter.innerText()).trim());
console.log(`contador ANTES = ${before}`);

const api = await pwRequest.newContext({ baseURL: BASE });
const html = await (await api.get(`/s/${survey.public_id}`)).text();
const token = html.match(/name="_token" value="([^"]+)"/)?.[1];
const single = survey.questions.find((q) => q.type === 'single_choice');
const multi = survey.questions.find((q) => q.type === 'multiple_choice');

const marca = posts.length;
const sent = Date.now();
const res = await api.post(`/s/${survey.public_id}/enviar`, {
    form: {
        _token: token,
        [`q[${single.id}]`]: String(single.options[0].id),
        [`q[${multi.id}][]`]: String(multi.options[0].id),
    },
    maxRedirects: 0,
    failOnStatusCode: false,
});
console.log(`envio HTTP -> ${res.status()}  (esperando hasta ${WATCH_S} s)`);

let changedAt = null;
let after = before;
for (let i = 0; i < WATCH_S * 10; i++) {
    await page.waitForTimeout(100);
    const v = Number((await counter.innerText()).trim());
    if (v !== before) { changedAt = Date.now() - sent; after = v; break; }
}

const tras = posts.slice(marca);
console.log(`contador DESPUES = ${after}`);
console.log(changedAt === null
    ? `*** EL CONTADOR NO CAMBIO EN ${WATCH_S} s — EL RESPALDO NO REFRESCA ***`
    : `*** CAMBIO a los ${changedAt} ms ***`);
console.log('POST tras el envio:');
for (const p of tras) console.log(`   +${p.at} ms  calls=${JSON.stringify(p.methods)}`);
console.log(`   refreshStats(SONDEO)=${tras.filter((p) => p.methods.includes('refreshStats')).length}` +
            `  setConnectionState=${tras.filter((p) => p.methods.includes('setConnectionState')).length}` +
            `  onStatsUpdated=${tras.filter((p) => p.methods.includes('onStatsUpdated')).length}`);
console.log(`errores de evaluacion por CSP en toda la sesion: ${evalErrors}`);

await api.dispose();
await browser.close();
process.exit(0);
