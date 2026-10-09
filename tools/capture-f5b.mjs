/*
 * capture-f5b.mjs — Fase 5b, verificacion visual propia.
 *
 * Captura las pantallas criticas CON DATOS REALES DEL SEEDER contra un
 * servidor de verdad. No son maquetas ni HTML estatico: cada captura es el
 * resultado de una peticion HTTP atendida por Laravel, con su sesion, sus
 * middleware y su base de datos.
 *
 * Uso:
 *   php artisan serve --host=127.0.0.1 --port=8000 &
 *   node tools/capture-f5b.mjs
 *
 * Chromium se lanza con --no-sandbox --disable-dev-shm-usage (no hay root ni
 * /dev/shm util en este contenedor). El ruido de D-Bus es esperado y no fatal.
 */

import { chromium } from '@playwright/test';
import { mkdirSync } from 'node:fs';

const BASE = process.env.EL_BASE ?? 'http://127.0.0.1:8000';
const OUT = 'storage/app/screenshots/f5b';

const ADMIN_EMAIL = 'admin@encuestalive.test';
const ADMIN_PASSWORD = 'password';

const SURVEY_ACTIVE = 'DEM0K7M2QX9RTB4W';
const SURVEY_CLOSED = 'DEM0CERRADA9XKT2';

mkdirSync(OUT, { recursive: true });

const shots = [];

async function shoot(page, name, opts = {}) {
  const path = `${OUT}/${name}.png`;
  await page.screenshot({ path, fullPage: opts.fullPage ?? true });
  shots.push(name);
  console.log(`  [captura] ${name}.png`);
}

/* Falla ruidosamente si la pantalla no contiene lo que debe: una captura de
 * una pagina de error tambien es un PNG, y se veria "correcta" en el informe. */
async function mustSee(page, text, screen) {
  const body = await page.textContent('body');
  if (!body || !body.includes(text)) {
    throw new Error(`[${screen}] no contiene "${text}". Captura invalida.`);
  }
}

const browser = await chromium.launch({
  args: ['--no-sandbox', '--disable-dev-shm-usage'],
});

try {
  /* ==================================================== PARTICIPANTE (390px)
   * Mobile-first real: el escenario del producto es un telefono en una sala.
   */
  const mobile = await browser.newContext({
    viewport: { width: 390, height: 844 },
    deviceScaleFactor: 2,
    isMobile: true,
    hasTouch: true,
  });

  const m = await mobile.newPage();

  console.log('\n· Participante (390px)');

  await m.goto(`${BASE}/s/${SURVEY_ACTIVE}`, { waitUntil: 'domcontentloaded' });
  await mustSee(m, 'Anonima', 'publica-movil');
  await shoot(m, '05-publica-movil-390');

  // Se responde DE VERDAD: se marcan opciones y se envia el formulario.
  await m.locator('input[type=radio]').first().check();

  const checkboxes = m.locator('input[type=checkbox]');
  const total = await checkboxes.count();
  for (let i = 0; i < Math.min(2, total); i++) {
    await checkboxes.nth(i).check();
  }

  const textarea = m.locator('textarea').first();
  if (await textarea.count()) {
    await textarea.fill('Capturado por la verificacion visual de la Fase 5b.');
  }

  await shoot(m, '06-publica-movil-rellena');

  await m.locator('button[type=submit]').first().click();
  await m.waitForSelector('text=Respuesta enviada', { timeout: 15000 });

  await mustSee(m, 'Respuesta enviada', 'confirmacion');
  await shoot(m, '07-confirmacion-movil');

  // Encuesta cerrada: rechazo visible, no un 404 desconcertante.
  await m.goto(`${BASE}/s/${SURVEY_CLOSED}`, { waitUntil: 'domcontentloaded' });
  await mustSee(m, 'Encuesta cerrada', 'cerrada');
  await shoot(m, '08-encuesta-cerrada-movil');

  await mobile.close();

  /* ========================================================= ADMIN (1440px) */
  const desktop = await browser.newContext({
    viewport: { width: 1440, height: 900 },
    deviceScaleFactor: 1,
  });

  const d = await desktop.newPage();

  console.log('\n· Administracion (1440px)');

  await d.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await mustSee(d, 'Entrar al panel', 'login');
  await shoot(d, '01-login');

  await d.fill('#email', ADMIN_EMAIL);
  await d.fill('#password', ADMIN_PASSWORD);
  await d.locator('button[type=submit]').first().click();
  await d.waitForSelector('text=Encuestas activas', { timeout: 15000 });

  await mustSee(d, 'Encuestas activas', 'panel');
  await shoot(d, '02-panel');

  // Editor de preguntas: se navega desde el listado, como lo haria una persona.
  await d.locator('a[href*="/editar"]').first().click();
  await d.waitForLoadState('domcontentloaded');
  await mustSee(d, 'Preguntas', 'editor');
  await shoot(d, '03-editor-preguntas');

  // Presentacion del QR: vive en la misma pantalla, seccion "Publicacion".
  const qr = d.locator('img[alt*="Codigo QR"]').first();
  if (await qr.count()) {
    await qr.scrollIntoViewIfNeeded();
    await shoot(d, '04-qr-y-enlace-publico');
  } else {
    console.log('  [aviso] sin QR en esta encuesta (no publicada)');
  }

  // Dashboard de resultados en vivo.
  await d.goto(`${BASE}/admin/encuestas`, { waitUntil: 'domcontentloaded' });
  await d.locator('a[href*="/resultados"]').first().click();
  await d.waitForLoadState('domcontentloaded');
  await mustSee(d, 'Sesiones conectadas', 'dashboard');
  await shoot(d, '09-dashboard-resultados');

  // Galeria de componentes en /dev/galeria (peticion de frontend-engineer).
  await d.goto(`${BASE}/dev/galeria`, { waitUntil: 'domcontentloaded' });
  await mustSee(d, 'componentes', 'galeria');
  await shoot(d, '10-dev-galeria', { fullPage: false });

  await desktop.close();

  console.log(`\nOK — ${shots.length} capturas en ${OUT}`);
} catch (e) {
  console.error('\nFALLO DE CAPTURA:', e.message);
  process.exitCode = 1;
} finally {
  await browser.close();
}
