/*
 * tools/f6/probe-bridge.mjs — prueba decisiva del defecto de CA-08.
 *
 * Ya esta demostrado (tools/f6/probe-ws.mjs) que el frame `stats.updated` LLEGA
 * al navegador y que el contador NO se mueve. Falta aislar el eslabon roto.
 *
 * Hipotesis: Livewire 4.4.7 NO trae puente con Echo. En Livewire 3 el atributo
 * #[On('echo-private:canal,evento')] activaba una suscripcion automatica; en el
 * paquete instalado no hay ni una sola aparicion de "Echo" ni de "echo-private"
 * (ni en dist/livewire.esm.js ni en src/**.php). Solo existe SupportPolling.
 * Si es cierto, ese atributo es codigo muerto y nada llama a refreshStats().
 *
 * EXPERIMENTO (dos mitades, misma pagina):
 *   A) Se envia una respuesta SIN tocar nada  -> el contador no debe moverse.
 *   B) Se conecta A MANO Echo -> Livewire.call('refreshStats') y se envia otra
 *      -> si ahora SI se mueve, el unico eslabon que faltaba es el puente.
 *
 * Si B funciona, el defecto queda localizado con precision quirurgica y la
 * correccion es de una linea en el cliente, no un rediseno.
 */
import { chromium, request as pwRequest } from '@playwright/test';
import { readFileSync } from 'node:fs';

const fx = JSON.parse(readFileSync(new URL('../../storage/app/f6/fixture.json', import.meta.url), 'utf8'));
const BASE = 'http://127.0.0.1:8000';
const survey = fx.surveys.active;

const api = await pwRequest.newContext({ baseURL: BASE });

/** Envia una respuesta como un participante nuevo (contexto HTTP propio). */
async function submitOnce(label) {
    const ctx = await pwRequest.newContext({ baseURL: BASE });
    const html = await (await ctx.get(`/s/${survey.public_id}`)).text();
    const token = html.match(/name="_token" value="([^"]+)"/)?.[1];
    const single = survey.questions.find((q) => q.type === 'single_choice');
    const multi = survey.questions.find((q) => q.type === 'multiple_choice');
    const res = await ctx.post(`/s/${survey.public_id}/enviar`, {
        form: {
            _token: token,
            [`q[${single.id}]`]: String(single.options[0].id),
            [`q[${multi.id}][]`]: String(multi.options[0].id),
        },
        maxRedirects: 0,
        failOnStatusCode: false,
    });
    console.log(`   envio ${label} -> HTTP ${res.status()}`);
    await ctx.dispose();
}

const browser = await chromium.launch({ args: ['--no-sandbox', '--disable-dev-shm-usage'] });
const page = await browser.newPage();

await page.goto(`${BASE}/login`);
await page.fill('input[name=email]', fx.admin.email);
await page.fill('input[name=password]', fx.admin.password);
await page.click('button[type=submit]');
await page.waitForURL((u) => !u.pathname.endsWith('/login'));
await page.goto(`${BASE}/admin/encuestas/${survey.id}/resultados`);
await page.waitForTimeout(6000);

const counter = () => page.evaluate(() => {
    const art = [...document.querySelectorAll('article')]
        .find((a) => a.querySelector('h3')?.textContent.includes('Respuestas completadas'));
    return art?.querySelector('p')?.innerText.trim();
});
const pollAttr = () => page.$eval('[data-dashboard]', (el) =>
    el.getAttributeNames().filter((n) => n.startsWith('wire:poll')).join(',') || '(ninguno)');

console.log('\n=== ESTADO INICIAL ===');
console.log('   window.Echo presente :', await page.evaluate(() => typeof window.Echo !== 'undefined'));
console.log('   wire:poll            :', await pollAttr());
console.log('   contador             :', await counter());

/* ------------------------------- MITAD A ------------------------------- */
console.log('\n=== A) SIN PUENTE (comportamiento actual del producto) ===');
const beforeA = await counter();
await submitOnce('A');
await page.waitForTimeout(8000);
const afterA = await counter();
console.log(`   contador ${beforeA} -> ${afterA}   ${beforeA === afterA ? '*** NO SE ACTUALIZA (defecto) ***' : 'se actualizo'}`);

/* ------------------------------- MITAD B ------------------------------- */
console.log('\n=== B) CON EL PUENTE Echo -> Livewire CONECTADO A MANO ===');
const wired = await page.evaluate((publicId) => {
    const el = document.querySelector('[data-dashboard]');
    const id = el?.closest('[wire\\:id]')?.getAttribute('wire:id') ?? el?.getAttribute('wire:id');
    if (!window.Echo || !id) return { ok: false, id, reason: 'sin Echo o sin wire:id' };

    window.__hits = 0;
    // Punto con el que empieza el nombre: el evento usa broadcastAs('stats.updated'),
    // asi que en Echo hay que escucharlo como '.stats.updated'.
    window.Echo.private(`survey.${publicId}.admin`).listen('.stats.updated', () => {
        window.__hits += 1;
        window.Livewire.find(id).call('refreshStats');
    });
    return { ok: true, id };
}, survey.public_id);
console.log('   puente instalado     :', JSON.stringify(wired));

const beforeB = await counter();
await submitOnce('B');
await page.waitForTimeout(8000);
const afterB = await counter();
const hits = await page.evaluate(() => window.__hits);
console.log(`   callbacks recibidos  : ${hits}`);
console.log(`   contador ${beforeB} -> ${afterB}   ${beforeB !== afterB ? '*** SE ACTUALIZA: el unico eslabon que faltaba era el puente ***' : 'sigue sin actualizarse'}`);

await browser.close();
await api.dispose();
