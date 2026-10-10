import { test, expect } from '@playwright/test';
import { mkdirSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { loadFixture, adminContext, statValue, ROOT } from './support/fixture.mjs';
import {
    observarDashboard, bloquearSondeo, leerDom, enviarRespuesta,
    esperarCambio, reverbVivo,
} from './support/realtime.mjs';

/*
 * ============================================================================
 * CA-08 · REVERIFICACION DE LA FASE 6c, tras la correccion de DEF-01
 * ============================================================================
 *
 * En la Fase 6 este fichero cerro CA-08 como FALLIDO: el frame `stats.updated`
 * llegaba al navegador y el dashboard no se movia, y ademas el `wire:poll`
 * desaparecia al conectar, dejando la pantalla congelada bajo el rotulo
 * "Conectado · en vivo".
 *
 * El commit 247ae21 hizo dos cosas: `pollInterval()` ya nunca devuelve null
 * (suelo permanente de 15 s) y `resources/js/realtime.js` conecta el puente
 * Echo -> Livewire que faltaba.
 *
 * ESTE FICHERO NO DA NADA POR BUENO. Vuelve a recorrer la cadena entera con el
 * difusor REAL y, sobre todo, RESUELVE LA AMBIGÜEDAD NUEVA que introduce la
 * propia correccion: con un sondeo de 15 s siempre activo, un contador que
 * sube no prueba que el WebSocket funcione. Ver `support/realtime.mjs` para el
 * discriminador (atribucion por nombre de metodo + bloqueo activo del sondeo +
 * latencia).
 *
 * ---------------------------------------------------------------------------
 * ADVERTENCIA IMPORTANTE SOBRE EL ENTORNO DE ESTAS PRUEBAS
 * ---------------------------------------------------------------------------
 * Se ha medido que, con la CSP de la Fase 7b (`script-src 'self'`, sin
 * 'unsafe-eval'), el sondeo `wire:poll` NO SE EJECUTA: el temporizador salta
 * pero Livewire evalua la expresion con `new Function()` y la CSP lo impide.
 * Eso es DEF-05 y se prueba en `ca08-fallback.spec.mjs`.
 *
 * Esa averia NO se aprovecha aqui como atajo. El bloqueo activo del sondeo se
 * aplica igualmente, para que el dia que DEF-05 se corrija estas pruebas
 * sigan distinguiendo las dos vias en lugar de pasar por inercia.
 * ---------------------------------------------------------------------------
 */

const fx = loadFixture();
const survey = fx.surveys.active;

const SHOTS = resolve(ROOT, 'storage/app/screenshots/f6c');
const DATOS = resolve(ROOT, 'storage/app/f6c');
mkdirSync(SHOTS, { recursive: true });
mkdirSync(DATOS, { recursive: true });

/** Abre el dashboard autenticado y deja el observador instalado. */
async function abrirDashboard(browser) {
    const ctx = await adminContext(browser, 'admin', { viewport: { width: 1280, height: 900 } });
    const page = await ctx.newPage();
    const obs = observarDashboard(page);

    let navegaciones = 0;
    page.on('load', () => { navegaciones += 1; });

    await page.goto(`/admin/encuestas/${survey.id}/resultados`);
    await expect(page.locator('[data-dashboard]')).toBeVisible();

    return { ctx, page, obs, navs: () => navegaciones };
}

/* ========================================================================= */

test('CA-08.1 · el evento real de Reverb llega al navegador del admin', async ({ browser, playwright }) => {
    test.setTimeout(120_000);
    test.skip(!(await reverbVivo()), 'Reverb debe estar EN PIE para esta prueba');

    const { ctx, page, obs } = await abrirDashboard(browser);

    await expect.poll(() => obs.suscrito(), { timeout: 30_000 }).toBeTruthy();

    const api = await playwright.request.newContext({ baseURL: 'http://127.0.0.1:8000' });
    expect([302, 303]).toContain(await enviarRespuesta(api, survey));

    await expect.poll(() => obs.framesStats(), { timeout: 30_000 }).toBeGreaterThan(0);

    writeFileSync(resolve(DATOS, 'ca08-ws-frames.json'), JSON.stringify({
        total_frames: obs.frames.length,
        suscripcion_ok: obs.suscrito(),
        frames_stats_updated: obs.framesStats(),
        muestra: obs.frames.filter((f) => f.payload.includes('stats.updated'))
            .slice(0, 1).map((f) => f.payload.slice(0, 500)),
    }, null, 2));

    await api.dispose();
    await ctx.close();
});

test('CA-08.2 · EL PASO 5: el dashboard se refresca por el WEBSOCKET, no por el sondeo', async ({ browser, playwright }) => {
    /*
     * Esta es LA prueba de la fase. Antes fallaba (DEF-01) y estaba marcada
     * con test.fail(). Ahora se exige que pase, y ademas se exige que pase
     * POR LA VIA CORRECTA.
     *
     * Tres controles, los tres deben cumplirse:
     *   1. se bloquea activamente todo `refreshStats` (el sondeo no puede ser)
     *   2. se exige observar un `onStatsUpdated` (atribucion positiva)
     *   3. la latencia debe ser muy inferior al suelo de 15 s
     */
    test.setTimeout(150_000);
    test.skip(!(await reverbVivo()), 'Reverb debe estar EN PIE para esta prueba');

    const { ctx, page, obs, navs } = await abrirDashboard(browser);
    const bloqueo = await bloquearSondeo(page);

    // El suelo de sondeo debe estar presente: es la correccion de DEF-01.
    await expect.poll(async () => (await leerDom(page)).estado, { timeout: 30_000 }).toBe('connected');
    const dom = await leerDom(page);
    expect(dom.poll, 'DEF-01: el suelo de sondeo debe seguir en el DOM').toEqual(['wire:poll.15s']);

    await expect.poll(() => obs.suscrito(), { timeout: 30_000 }).toBeTruthy();

    const completadas = statValue(page, 'Respuestas completadas');
    const antes = (await completadas.innerText()).trim();
    const navsAntes = navs();
    const marca = obs.marca();

    await page.screenshot({ path: resolve(SHOTS, 'ca08-01-conectado-con-suelo.png'), fullPage: true });

    const api = await playwright.request.newContext({ baseURL: 'http://127.0.0.1:8000' });
    const inicio = Date.now();
    expect([302, 303]).toContain(await enviarRespuesta(api, survey));

    const cambio = await esperarCambio(page, completadas, antes, 40_000);
    const latencia = cambio.ms;

    const diagnostico = {
        contador_antes: antes,
        contador_despues: cambio.valor,
        cambio: cambio.cambio,
        latencia_ms: latencia,
        suelo_sondeo_ms: 15_000,
        peticiones_tras_envio: obs.resumen(marca),
        sondeos_observados: obs.sondeos(marca),
        sondeos_bloqueados: bloqueo.total(),
        puentes_observados: obs.puentes(marca),
        frames_stats_updated: obs.framesStats(),
        errores_csp: obs.erroresCsp.length,
    };
    writeFileSync(resolve(DATOS, 'ca08-atribucion.json'), JSON.stringify(diagnostico, null, 2));
    console.log('[CA-08.2] atribucion:', JSON.stringify(diagnostico, null, 2));

    // 1. El refresco ocurrio.
    expect(cambio.cambio, 'el contador debe subir sin recargar').toBeTruthy();
    expect(Number(cambio.valor)).toBe(Number(antes) + 1);

    // 2. ATRIBUCION POSITIVA: lo pidio el puente del WebSocket.
    expect(obs.puentes(marca), 'debe observarse una llamada onStatsUpdated').toBeGreaterThan(0);

    // 3. EXCLUSION: ningun sondeo llego al servidor.
    expect(obs.sondeos(marca), 'ningun refreshStats debe haber llegado al servidor').toBe(0);

    // 4. LATENCIA: imposible de explicar por un sondeo de 15 s.
    expect(latencia, `latencia ${latencia} ms debe ser muy inferior al suelo de 15 s`).toBeLessThan(10_000);

    // 5. Sin recarga de pagina.
    expect(navs(), 'el dashboard no debe haberse recargado').toBe(navsAntes);

    await page.screenshot({ path: resolve(SHOTS, 'ca08-02-refrescado-por-websocket.png'), fullPage: true });
    await api.dispose();
    await ctx.close();
});

test('CA-08.3 · DEF-01 no ha vuelto: el suelo de sondeo sigue en el DOM con Reverb en pie', async ({ browser }) => {
    /*
     * Guardia de regresion del nucleo de la correccion. Antes, al pasar a
     * `connected`, el atributo DESAPARECIA. Se comprueba el DOM ya estable,
     * no el primer render, porque el valor inicial es `degraded` por diseno.
     */
    test.setTimeout(90_000);
    test.skip(!(await reverbVivo()), 'Reverb debe estar EN PIE para esta prueba');

    const { ctx, page } = await abrirDashboard(browser);

    await expect.poll(async () => (await leerDom(page)).estado, { timeout: 30_000 }).toBe('connected');

    const dom = await leerDom(page);
    expect(dom.poll).toEqual(['wire:poll.15s']);
    expect(dom.intervalo).toBe('15s');

    // Y se mantiene: no es un parpadeo que desaparezca en el siguiente morph.
    await page.waitForTimeout(8_000);
    const dom2 = await leerDom(page);
    expect(dom2.poll, 'el suelo debe seguir ahi pasados 8 s').toEqual(['wire:poll.15s']);
    expect(dom2.estado).toBe('connected');

    await ctx.close();
});

test('CA-08.5 · el canal privado rechaza a un administrador ajeno (403)', async ({ browser }) => {
    const ctx = await adminContext(browser, 'other');
    const page = await ctx.newPage();

    const res = await page.request.post('/broadcasting/auth', {
        form: { socket_id: '1234.5678', channel_name: `private-survey.${survey.public_id}.admin` },
        failOnStatusCode: false,
    });

    expect(res.status(), 'ser admin no basta: hace falta ser dueno de ESA encuesta').toBe(403);
    await ctx.close();
});
