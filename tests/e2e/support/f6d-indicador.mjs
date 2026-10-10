/*
 * ============================================================================
 * Sonda de DEF-03 y DEF-04 — el indicador de conexion, leido del DOM
 * ============================================================================
 *
 * Se comprueban en el DOM, no mirando una captura:
 *
 *   DEF-03  el indicador de la barra superior se queda fijo en "Conectado"
 *           aunque no haya tiempo real.
 *   DEF-04  `setConnectionState` (app.js:332) escribe en TODOS los nodos
 *           `[data-connection]` y nadie lee la marca `data-connection-managed`,
 *           asi que la correccion que introdujo esa marca esta a medias.
 *
 * Se ejecuta con REVERB PARADO: es el escenario en el que el indicador tiene
 * que decir la verdad.
 *
 * Uso:  node tests/e2e/support/f6d-indicador.mjs
 */
import { chromium } from '@playwright/test';
import { mkdirSync, writeFileSync, readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
const DATOS = resolve(ROOT, 'storage/app/f6d');
mkdirSync(DATOS, { recursive: true });

const fx = JSON.parse(readFileSync(resolve(ROOT, 'storage/app/f6/fixture.json'), 'utf8'));
const survey = fx.surveys.active;

const browser = await chromium.launch({ args: ['--no-sandbox', '--disable-dev-shm-usage'] });
const ctx = await browser.newContext({
    storageState: resolve(ROOT, 'storage/app/f6/auth-admin.json'),
    viewport: { width: 1280, height: 900 },
    baseURL: 'http://127.0.0.1:8000',
});
const page = await ctx.newPage();

await page.goto(`/admin/encuestas/${survey.id}/resultados`);
await page.locator('[data-dashboard]').waitFor({ state: 'visible', timeout: 30_000 });

// Se deja estabilizar: sin Reverb debe caer a `degraded`.
await page.waitForTimeout(25_000);

const lectura = await page.evaluate(() => {
    const panel = document.querySelector('[data-dashboard]');
    return {
        dashboard: panel && {
            estado: panel.getAttribute('data-connection-state'),
            poll: panel.getAttributeNames().filter((n) => n.startsWith('wire:poll')),
        },
        indicadores: [...document.querySelectorAll('[data-connection]')].map((el) => ({
            estado: el.getAttribute('data-connection'),
            managed: el.getAttribute('data-connection-managed'),
            role: el.getAttribute('role'),
            texto: (el.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 80),
        })),
    };
});

/*
 * DEF-03: el dashboard sabe que NO hay tiempo real (`degraded`/`reconnecting`)
 * pero algun indicador sigue rotulando "Conectado"/"online".
 */
const sinTiempoReal = ['degraded', 'reconnecting'].includes(lectura.dashboard?.estado);
const mintiendo = lectura.indicadores.filter(
    (i) => /conectado|en vivo|online/i.test(i.texto) || i.estado === 'online',
);

/*
 * DEF-04: existe al menos un indicador marcado `managed="livewire"`, es decir
 * cuyo dueno declarado es el servidor, pero `setConnectionState` de app.js no
 * filtra por esa marca: la escritura alcanza a TODOS los `[data-connection]`.
 */
const marcadosLivewire = lectura.indicadores.filter((i) => i.managed === 'livewire');

const informe = {
    reverb: 'PARADO',
    dashboard: lectura.dashboard,
    sin_tiempo_real_segun_el_componente: sinTiempoReal,
    indicadores: lectura.indicadores,
    DEF_03: {
        descripcion: 'indicador que sigue diciendo Conectado sin tiempo real',
        indicadores_que_mienten: mintiendo,
        abierto: sinTiempoReal && mintiendo.length > 0,
    },
    DEF_04: {
        descripcion: 'la marca managed existe en el DOM pero app.js no la lee',
        indicadores_marcados_livewire: marcadosLivewire,
        nota: 'app.js:332 hace querySelectorAll("[data-connection]") sin filtrar por managed',
    },
};

writeFileSync(resolve(DATOS, 'def03-def04-indicador.json'), JSON.stringify(informe, null, 2));
console.log(JSON.stringify(informe, null, 2));

await ctx.close();
await browser.close();
