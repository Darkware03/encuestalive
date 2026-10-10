/*
 * EXPERIMENTO CONTROLADO: ¿es la CSP la causa de que el sondeo no dispare?
 *
 * Una sola variable cambia entre las dos pasadas: la cabecera
 * Content-Security-Policy que recibe el navegador. Todo lo demas —servidor,
 * base de datos, build, Reverb caido— es identico.
 *
 *   Pasada 1 (CONTROL)     : CSP tal cual la sirve la aplicacion.
 *   Pasada 2 (EXPERIMENTAL): se le anade 'unsafe-eval' a script-src.
 *
 * Si el sondeo dispara en la pasada 2 y no en la 1, la causa queda
 * establecida por diferencia y no por conjetura.
 *
 * REQUISITO: Reverb PARADO, para que el sondeo sea la unica via posible.
 *
 * Uso:  node tests/e2e/support/csp-experiment.mjs [observacion_s]
 */
import { chromium } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(here, '../../..');
const fx = JSON.parse(readFileSync(resolve(ROOT, 'storage/app/f6/fixture.json'), 'utf8'));
const survey = fx.surveys.active;
const WATCH_S = Number(process.argv[2] ?? 30);
const BASE = 'http://127.0.0.1:8000';
const LW = /\/livewire[^/]*\/update$/;

async function pasada(etiqueta, permitirEval) {
    const browser = await chromium.launch({ args: ['--no-sandbox', '--disable-dev-shm-usage'] });
    const ctx = await browser.newContext({
        storageState: resolve(ROOT, 'storage/app/f6/auth-admin.json'),
        baseURL: BASE,
        viewport: { width: 1280, height: 900 },
    });
    const page = await ctx.newPage();

    if (permitirEval) {
        // Reescribe SOLO la cabecera CSP del documento. Ni una linea de la
        // aplicacion cambia.
        await page.route('**/*', async (route) => {
            if (route.request().resourceType() !== 'document') return route.fallback();
            const res = await route.fetch();
            const headers = { ...res.headers() };
            for (const k of Object.keys(headers)) {
                if (k.toLowerCase() === 'content-security-policy') {
                    headers[k] = headers[k].replace("script-src 'self'", "script-src 'self' 'unsafe-eval'");
                }
            }
            await route.fulfill({ response: res, headers });
        });
    }

    const posts = [];
    let evalErrors = 0;
    const t0 = Date.now();
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

    const csp = await page.evaluate(async () => {
        const r = await fetch(location.href, { method: 'GET' });
        return r.headers.get('content-security-policy') ?? '(ninguna)';
    });

    await page.waitForTimeout(WATCH_S * 1000);

    const dom = await page.evaluate(() => {
        const el = document.querySelector('[data-dashboard]');
        return {
            poll: el.getAttributeNames().filter((n) => n.startsWith('wire:poll')),
            state: el.getAttribute('data-connection-state'),
        };
    });

    const sondeos = posts.filter((p) => p.methods.includes('refreshStats')).length;
    console.log(`\n### ${etiqueta}`);
    console.log(`script-src recibido : ${(csp.match(/script-src[^;]*/) ?? ['(n/d)'])[0]}`);
    console.log(`DOM                 : poll=${JSON.stringify(dom.poll)} estado=${dom.state}`);
    console.log(`POST totales        : ${posts.length}  ${JSON.stringify(posts.map((p) => p.at + 'ms:' + p.methods.join('|')))}`);
    console.log(`SONDEOS refreshStats: ${sondeos}   (en ${WATCH_S}s con poll de ${dom.poll[0] ?? 'n/d'})`);
    console.log(`errores CSP de eval : ${evalErrors}`);

    await browser.close();
    return { sondeos, evalErrors };
}

const control = await pasada(`PASADA 1 · CONTROL — CSP real de la aplicacion`, false);
const exper = await pasada(`PASADA 2 · EXPERIMENTAL — CSP + 'unsafe-eval'`, true);

console.log('\n================= CONCLUSION =================');
console.log(`sondeos con CSP real          : ${control.sondeos}  (errores de eval: ${control.evalErrors})`);
console.log(`sondeos con 'unsafe-eval'     : ${exper.sondeos}  (errores de eval: ${exper.evalErrors})`);
console.log(control.sondeos === 0 && exper.sondeos > 0
    ? "CAUSA ESTABLECIDA: la CSP sin 'unsafe-eval' impide que wire:poll se ejecute."
    : 'Resultado NO concluyente: revisar antes de afirmar causa.');
process.exit(0);
