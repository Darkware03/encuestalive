/*
 * ============================================================================
 * Sonda independiente de la Fase 6d — ¿emite peticiones el sondeo `wire:poll`?
 * ============================================================================
 *
 * No es una prueba de Playwright: es un instrumento de medida suelto, para
 * responder a UNA pregunta sin el ruido del arnes de pruebas, y para poder
 * contrastar despues que la bateria mide lo mismo que la sonda.
 *
 * Mide, en una ventana de N segundos sobre el dashboard ya autenticado:
 *   · peticiones POST a Livewire, clasificadas por el metodo que invocan
 *   · errores de consola relacionados con la CSP / evaluacion de expresiones
 *   · el intervalo de sondeo que declara el DOM
 *
 * Uso:
 *   node tests/e2e/support/f6d-sonda.mjs [segundos] [etiqueta]
 */
import { chromium } from '@playwright/test';
import { mkdirSync, writeFileSync, readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
const VENTANA_S = Number(process.argv[2] ?? 35);
const ETIQUETA = process.argv[3] ?? 'sonda';
const DATOS = resolve(ROOT, 'storage/app/f6d');
mkdirSync(DATOS, { recursive: true });

const fx = JSON.parse(readFileSync(resolve(ROOT, 'storage/app/f6/fixture.json'), 'utf8'));
const survey = fx.surveys.active;
const LIVEWIRE_UPDATE = /\/livewire[^/]*\/update$/;

function metodosDe(body) {
    try {
        const j = JSON.parse(body ?? '{}');
        return (j.components ?? []).flatMap((c) => (c.calls ?? []).map((k) => k.method));
    } catch {
        return [];
    }
}

const browser = await chromium.launch({ args: ['--no-sandbox', '--disable-dev-shm-usage'] });
const ctx = await browser.newContext({
    storageState: resolve(ROOT, 'storage/app/f6/auth-admin.json'),
    viewport: { width: 1280, height: 900 },
    baseURL: 'http://127.0.0.1:8000',
});
const page = await ctx.newPage();

const t0 = Date.now();
const peticiones = [];
const erroresCsp = [];
page.on('console', (m) => {
    const t = m.text();
    if (/unsafe-eval|EvalError|Expression Error/i.test(t)) {
        erroresCsp.push({ at: Date.now() - t0, text: t.slice(0, 200) });
    }
});
page.on('pageerror', (e) => {
    if (/unsafe-eval|EvalError/i.test(String(e))) {
        erroresCsp.push({ at: Date.now() - t0, text: String(e).slice(0, 200) });
    }
});
page.on('request', (req) => {
    if (req.method() !== 'POST') return;
    let pathname;
    try { pathname = new URL(req.url()).pathname; } catch { return; }
    if (!LIVEWIRE_UPDATE.test(pathname)) return;
    peticiones.push({ at: Date.now() - t0, metodos: metodosDe(req.postData()) });
});

await page.goto(`/admin/encuestas/${survey.id}/resultados`);
await page.locator('[data-dashboard]').waitFor({ state: 'visible', timeout: 30_000 });

const leerDom = () => page.evaluate(() => {
    const el = document.querySelector('[data-dashboard]');
    return el && {
        poll: el.getAttributeNames().filter((n) => n.startsWith('wire:poll')),
        estado: el.getAttribute('data-connection-state'),
        intervalo: el.getAttribute('data-poll-interval'),
    };
});

const domInicial = await leerDom();
const marca = peticiones.length;
await page.waitForTimeout(VENTANA_S * 1000);
const domFinal = await leerDom();

const desde = peticiones.slice(marca);
const sondeos = desde.filter((p) => p.metodos.includes('refreshStats'));

const informe = {
    etiqueta: ETIQUETA,
    ventana_s: VENTANA_S,
    dom_inicial: domInicial,
    dom_final: domFinal,
    sondeos_emitidos: sondeos.length,
    errores_csp: erroresCsp.length,
    muestra_error_csp: erroresCsp.slice(0, 2),
    cadencia_sondeos_ms: sondeos.map((p) => p.at),
    todas_las_peticiones: desde.map((p) => `+${p.at}ms ${p.metodos.join('|') || '(sin calls)'}`),
};
writeFileSync(resolve(DATOS, `sonda-${ETIQUETA}.json`), JSON.stringify(informe, null, 2));
console.log(JSON.stringify(informe, null, 2));

await ctx.close();
await browser.close();
