/*
 * tools/f6/probe-ws.mjs — sonda del camino completo de tiempo real.
 *
 * Responde a UNA pregunta: cuando un participante envia una respuesta, ¿llega
 * el evento `stats.updated` al navegador del administrador?
 *
 * Registra, por orden:
 *   · cada peticion a /broadcasting/auth y su codigo de estado;
 *   · cada frame ENVIADO y RECIBIDO por el WebSocket;
 *   · los errores de consola de la pagina.
 *
 * Luego envia una respuesta real por HTTP (con su propia cookie de sesion,
 * como un participante distinto) y vuelve a volcar los frames.
 */
import { chromium, request as pwRequest } from '@playwright/test';
import { readFileSync } from 'node:fs';

const fx = JSON.parse(readFileSync(new URL('../../storage/app/f6/fixture.json', import.meta.url), 'utf8'));
const BASE = 'http://127.0.0.1:8000';
const survey = fx.surveys.active;

const browser = await chromium.launch({ args: ['--no-sandbox', '--disable-dev-shm-usage'] });
const page = await browser.newPage();

const log = [];
page.on('websocket', (ws) => {
    log.push(`WS ABIERTO ${ws.url()}`);
    ws.on('framesent', (f) => log.push(`  --> ${String(f.payload).slice(0, 260)}`));
    ws.on('framereceived', (f) => log.push(`  <-- ${String(f.payload).slice(0, 260)}`));
    ws.on('close', () => log.push('WS CERRADO'));
    ws.on('socketerror', (e) => log.push(`WS ERROR ${e}`));
});
page.on('response', (r) => {
    if (r.url().includes('broadcasting/auth')) log.push(`AUTH ${r.status()} ${r.url()}`);
    if (r.url().includes('livewire/update')) log.push(`LIVEWIRE ${r.status()}`);
});
page.on('pageerror', (e) => log.push(`PAGEERROR ${String(e).slice(0, 200)}`));
page.on('console', (m) => {
    if (m.type() === 'error' || m.type() === 'warning') log.push(`CONSOLE ${m.type()} ${m.text().slice(0, 200)}`);
});

await page.goto(`${BASE}/login`);
await page.fill('input[name=email]', fx.admin.email);
await page.fill('input[name=password]', fx.admin.password);
await page.click('button[type=submit]');
await page.waitForURL((u) => !u.pathname.endsWith('/login'));
await page.goto(`${BASE}/admin/encuestas/${survey.id}/resultados`);
await page.waitForTimeout(6000);

const readCounter = () => page.$eval(
    'article:has(h3)',
    () => null,
).catch(() => null);

const counter = async () => page.evaluate(() => {
    const art = [...document.querySelectorAll('article')]
        .find((a) => a.querySelector('h3')?.textContent.includes('Respuestas completadas'));
    return art?.querySelector('p')?.innerText.trim();
});

console.log('contador ANTES =', await counter());
console.log('\n--- frames hasta la suscripcion ---');
console.log(log.join('\n'));
log.length = 0;

/* ---- participante independiente que envia por HTTP, como otra persona ---- */
const api = await pwRequest.newContext({ baseURL: BASE });
const showRes = await api.get(`/s/${survey.public_id}`);
const html = await showRes.text();
const token = html.match(/name="_token" value="([^"]+)"/)?.[1];
const single = survey.questions.find((q) => q.type === 'single_choice');
const multi = survey.questions.find((q) => q.type === 'multiple_choice');

const form = new URLSearchParams();
form.set('_token', token);
form.set(`q[${single.id}]`, String(single.options[0].id));
form.append(`q[${multi.id}][]`, String(multi.options[1].id));

const post = await api.post(`/s/${survey.public_id}/enviar`, {
    form: Object.fromEntries(form),
    maxRedirects: 0,
    failOnStatusCode: false,
});
console.log('\nenvio HTTP ->', post.status());

await page.waitForTimeout(8000);
console.log('contador DESPUES =', await counter());
console.log('\n--- frames tras el envio ---');
console.log(log.length ? log.join('\n') : '(NINGUN frame nuevo)');

await browser.close();
await api.dispose();
