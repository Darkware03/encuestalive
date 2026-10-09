/*
 * tools/f6/probe-indicator.mjs — sonda del indicador de conexion.
 *
 * No es una prueba: es una sonda de diagnostico. Vuelca el outerHTML de TODOS
 * los `[data-connection]` a lo largo del tiempo para distinguir entre
 *   (a) un fallo real del producto, y
 *   (b) un selector mal escrito por mi.
 *
 * Un defecto sin reproduccion exacta es una hipotesis, no un defecto.
 */
import { chromium } from '@playwright/test';
import { readFileSync } from 'node:fs';

const fx = JSON.parse(readFileSync(new URL('../../storage/app/f6/fixture.json', import.meta.url), 'utf8'));
const BASE = 'http://127.0.0.1:8000';

const browser = await chromium.launch({ args: ['--no-sandbox', '--disable-dev-shm-usage'] });
const page = await browser.newPage();

page.on('console', (m) => console.log('  [console]', m.type(), m.text().slice(0, 200)));
page.on('pageerror', (e) => console.log('  [pageerror]', String(e).slice(0, 300)));

await page.goto(`${BASE}/login`);
await page.fill('input[name=email]', fx.admin.email);
await page.fill('input[name=password]', fx.admin.password);
await page.click('button[type=submit]');
await page.waitForURL((u) => !u.pathname.endsWith('/login'));

await page.goto(`${BASE}/admin/encuestas/${fx.surveys.active.id}/resultados`);

const dump = async (tag) => {
    const items = await page.$$eval('[data-connection]', (els) =>
        els.map((el) => ({
            state: el.getAttribute('data-connection'),
            cls: (el.getAttribute('class') || '').split(/\s+/).filter((c) => c.startsWith('text-connection')).join(','),
            text: el.innerText.replace(/\s+/g, ' ').trim().slice(0, 50),
            inDashboard: !!el.closest('[data-dashboard]'),
        })));
    const poll = await page.$eval('[data-dashboard]', (el) =>
        el.getAttributeNames().filter((n) => n.startsWith('wire:poll')).join(',') || '(ninguno)');
    console.log(`\n== ${tag} == wire:poll=${poll}`);
    for (const i of items) {
        console.log(`   dashboard=${i.inDashboard ? 'SI' : 'NO'} data-connection=${i.state} clase=${i.cls} texto="${i.text}"`);
    }
};

await dump('t=0 justo tras cargar');
await page.waitForTimeout(3000);
await dump('t=3s');
await page.waitForTimeout(5000);
await dump('t=8s');

await browser.close();
