/*
 * Sonda decisiva: ¿que via refresca realmente el dashboard?
 *
 * Abre el dashboard, envia una respuesta real por HTTP y observa:
 *   · si el contador sube, y en cuanto tiempo
 *   · que peticiones POST lo provocaron, clasificadas por metodo:
 *        refreshStats    -> SONDEO (wire:poll)
 *        onStatsUpdated  -> PUENTE Echo (WebSocket)
 *
 * Uso:  node tests/e2e/support/refresh-probe.mjs [etiqueta]
 */
import { chromium, request as pwRequest } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(here, '../../..');
const fx = JSON.parse(readFileSync(resolve(ROOT, 'storage/app/f6/fixture.json'), 'utf8'));
const survey = fx.surveys.active;
const LABEL = process.argv[2] ?? 'sin-etiqueta';
const BASE = 'http://127.0.0.1:8000';

const LW = /\/livewire[^/]*\/update$/;

const browser = await chromium.launch({ args: ['--no-sandbox', '--disable-dev-shm-usage'] });
const ctx = await browser.newContext({
    storageState: resolve(ROOT, 'storage/app/f6/auth-admin.json'),
    baseURL: BASE,
    viewport: { width: 1280, height: 900 },
});
const page = await ctx.newPage();

const posts = [];
let t0 = Date.now();
const frames = [];
page.on('websocket', (ws) => ws.on('framereceived', (f) => frames.push({ at: Date.now() - t0, p: String(f.payload) })));
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

// Deja que Echo se asiente (o que se confirme que no hay socket).
await page.waitForTimeout(9000);

const dom = await page.evaluate(() => {
    const el = document.querySelector('[data-dashboard]');
    return {
        poll: el.getAttributeNames().filter((n) => n.startsWith('wire:poll')),
        state: el.getAttribute('data-connection-state'),
    };
});

const before = Number((await counter.innerText()).trim());
console.log(`\n### ${LABEL}`);
console.log(`DOM: poll=${JSON.stringify(dom.poll)} estado=${dom.state}`);
console.log(`contador ANTES = ${before}`);

// ---- envio real de una respuesta, por HTTP, como otra persona ----
const api = await pwRequest.newContext({ baseURL: BASE });
const html = await (await api.get(`/s/${survey.public_id}`)).text();
const token = html.match(/name="_token" value="([^"]+)"/)?.[1];
const single = survey.questions.find((q) => q.type === 'single_choice');
const multi = survey.questions.find((q) => q.type === 'multiple_choice');

posts.length = 0;
frames.length = 0;
t0 = Date.now();
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
console.log(`envio HTTP -> ${res.status()}`);

// ---- espera hasta 40 s a que el contador cambie ----
let changedAt = null;
let after = before;
for (let i = 0; i < 400; i++) {
    await page.waitForTimeout(100);
    const v = Number((await counter.innerText()).trim());
    if (v !== before) { changedAt = Date.now() - sent; after = v; break; }
}

console.log(`contador DESPUES = ${after}`);
console.log(changedAt === null
    ? '*** EL CONTADOR NO CAMBIO EN 40 s ***'
    : `*** CAMBIO a los ${changedAt} ms ***`);

console.log(`frames stats.updated recibidos: ${frames.filter((f) => f.p.includes('stats.updated')).length}`);
console.log('POST /livewire/update tras el envio:');
for (const p of posts) console.log(`   +${p.at} ms  calls=${JSON.stringify(p.methods)}`);
console.log(`   refreshStats(SONDEO)=${posts.filter((p) => p.methods.includes('refreshStats')).length}` +
            `  onStatsUpdated(WEBSOCKET)=${posts.filter((p) => p.methods.includes('onStatsUpdated')).length}`);

await api.dispose();
await browser.close();
process.exit(0);
