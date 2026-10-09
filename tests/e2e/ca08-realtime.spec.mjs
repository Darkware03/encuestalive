import { test, expect } from '@playwright/test';
import { writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { loadFixture, adminContext, statValue, SHOTS, ROOT } from './support/fixture.mjs';

/*
 * ============================================================================
 * CA-08 EXTREMO A EXTREMO — con el difusor REAL, no con BROADCAST_CONNECTION=log
 * ============================================================================
 *
 * Lo que ya estaba probado antes de la Fase 6:
 *   · Reverb arranca y responde HTTP 101 (Fase 3).
 *   · El respaldo `wire:poll` refresca el dashboard (Fase 5b).
 *   · Las capturas de la Fase 5b se tomaron SIN difusor real.
 *
 * Lo que verifica este fichero, que es lo que nadie habia hecho: si un evento
 * REAL de Reverb llega al navegador y dispara el refresco del dashboard.
 *
 * RESULTADO: NO. CA-08 esta FALLIDO por la via de tiempo real. Ver DEF-01.
 *
 * ------------------------------- EL HALLAZGO -------------------------------
 * Las cinco pruebas de abajo trocean la cadena para senalar el eslabon exacto:
 *
 *   1. El frame `stats.updated` SI llega al navegador           -> PASA
 *   2. El dashboard NO se actualiza con el frame recibido       -> FALLA (DEF-01)
 *   3. Conectando a mano Echo -> Livewire, SI se actualiza      -> PASA
 *   4. Con Reverb caido, el respaldo `wire:poll` SI actualiza   -> PASA
 *   5. El canal privado rechaza a un admin ajeno (403)          -> PASA
 *
 * Causa raiz: livewire/livewire v4.4.7 NO TRAE PUENTE CON ECHO. No hay ni una
 * aparicion de "Echo" ni de "echo-private" en `dist/livewire.esm.js` ni en
 * `src/**.php` (solo existe SupportPolling). El atributo
 *   #[On('echo-private:survey.{survey.public_id}.admin,stats.updated')]
 * de LiveDashboard es, en esta version, CODIGO MUERTO: registra un oyente para
 * un nombre de evento literal que nada emite.
 *
 * Y el agravante: `realtime.js` SI consigue poner el componente en
 * `connected`, lo que hace que `pollInterval()` devuelva null y DESAPAREZCA el
 * `wire:poll`. Es decir, el respaldo obligatorio de D-02 se desactiva
 * justamente cuando Reverb funciona. El dashboard se queda congelado mientras
 * muestra "Conectado · en vivo". Solo se actualiza cuando Reverb esta CAIDO.
 * ---------------------------------------------------------------------------
 */

const fx = loadFixture();
const survey = fx.surveys.active;

/** Abre el dashboard autenticado y espera a que Echo confirme la conexion. */
async function openDashboard(browser) {
    const ctx = await adminContext(browser, 'admin', { viewport: { width: 1280, height: 900 } });
    const page = await ctx.newPage();

    const frames = [];
    const sockets = [];
    page.on('websocket', (ws) => {
        sockets.push(ws.url());
        ws.on('framereceived', (f) => frames.push(String(f.payload)));
    });

    let navigations = 0;
    page.on('load', () => { navigations += 1; });

    await page.goto(`/admin/encuestas/${survey.id}/resultados`);
    await expect(page.locator('[data-dashboard]')).toBeVisible();

    return { ctx, page, frames, sockets, navs: () => navigations };
}

/* Un participante independiente que envia por HTTP: contexto de peticiones
 * propio, luego cookie opaca propia, luego "otra persona". */
async function submitAsNewParticipant(request) {
    const html = await (await request.get(`/s/${survey.public_id}`)).text();
    const token = html.match(/name="_token" value="([^"]+)"/)?.[1];
    const single = survey.questions.find((q) => q.type === 'single_choice');
    const multi = survey.questions.find((q) => q.type === 'multiple_choice');

    const res = await request.post(`/s/${survey.public_id}/enviar`, {
        form: {
            _token: token,
            [`q[${single.id}]`]: String(single.options[0].id),
            [`q[${multi.id}][]`]: String(multi.options[0].id),
        },
        maxRedirects: 0,
        failOnStatusCode: false,
    });
    expect([302, 303]).toContain(res.status());
}

/* ========================================================================= */

test('CA-08.1 · el evento real de Reverb SI llega al navegador del admin', async ({ browser, playwright }) => {
    test.setTimeout(120_000);
    const { ctx, page, frames, sockets } = await openDashboard(browser);

    // Espera a la suscripcion al canal privado.
    await expect
        .poll(() => frames.some((f) => f.includes('pusher_internal:subscription_succeeded')), { timeout: 30_000 })
        .toBeTruthy();

    const api = await playwright.request.newContext({ baseURL: 'http://127.0.0.1:8000' });
    await submitAsNewParticipant(api);

    await expect
        .poll(() => frames.filter((f) => f.includes('stats.updated')).length, { timeout: 30_000 })
        .toBeGreaterThan(0);

    const statsFrames = frames.filter((f) => f.includes('stats.updated'));
    writeFileSync(resolve(ROOT, 'storage/app/f6/ca08-ws-frames.json'), JSON.stringify({
        sockets,
        total_frames: frames.length,
        connection_established: frames.some((f) => f.includes('pusher:connection_established')),
        subscription_succeeded: frames.some((f) => f.includes('pusher_internal:subscription_succeeded')),
        stats_updated_frames: statsFrames.length,
        sample: statsFrames.slice(0, 1).map((f) => f.slice(0, 500)),
    }, null, 2));

    expect(sockets.some((u) => u.includes(':8080'))).toBeTruthy();
    await api.dispose();
    await ctx.close();
});

test('CA-08.2 · DEFECTO DEF-01: el dashboard NO se refresca con el evento recibido', async ({ browser, playwright }) => {
    /*
     * test.fail(): se declara que esta prueba DEBE fallar mientras DEF-01 siga
     * abierto. No se silencia el defecto; se deja escrito y ejecutandose, de
     * modo que el dia que alguien conecte el puente la prueba pasara y
     * Playwright avisara de que la anotacion sobra. Un `expect` comentado no
     * avisa de nada.
     */
    test.fail(true, 'DEF-01: Livewire 4.4.7 no tiene puente con Echo; #[On(echo-private:...)] es codigo muerto');
    test.setTimeout(120_000);

    const { ctx, page, frames, navs } = await openDashboard(browser);

    // Con la conexion establecida el respaldo se apaga: cualquier cambio en
    // pantalla solo puede venir del WebSocket.
    await expect
        .poll(async () => page.evaluate(() => document.querySelector('[data-dashboard]')
            .getAttributeNames().filter((n) => n.startsWith('wire:poll'))), { timeout: 30_000 })
        .toEqual([]);

    const navsBefore = navs();
    const completed = statValue(page, 'Respuestas completadas');
    const before = Number((await completed.innerText()).trim());

    await page.screenshot({ path: resolve(SHOTS, 'ca08-01-dashboard-conectado.png'), fullPage: true });

    const api = await playwright.request.newContext({ baseURL: 'http://127.0.0.1:8000' });
    await submitAsNewParticipant(api);

    // El frame llega...
    await expect.poll(() => frames.filter((f) => f.includes('stats.updated')).length, { timeout: 30_000 })
        .toBeGreaterThan(0);

    // ...pero el contador no se mueve. AQUI es donde CA-08 se rompe.
    await expect(completed).toHaveText(String(before + 1), { timeout: 20_000 });
    expect(navs(), 'el dashboard no debe haberse recargado').toBe(navsBefore);

    await page.screenshot({ path: resolve(SHOTS, 'ca08-02-dashboard-actualizado.png'), fullPage: true });
    await api.dispose();
    await ctx.close();
});

test('CA-08.3 · con el puente Echo->Livewire conectado a mano, SI se refresca', async ({ browser, playwright }) => {
    /*
     * Aisla la culpa: si al anadir las dos lineas que faltan el dashboard se
     * actualiza, entonces el transporte, la autorizacion del canal, el evento,
     * el payload y StatsService estan todos bien, y lo unico que falta es el
     * puente del cliente. Esto convierte DEF-01 en un defecto de una linea y
     * no en "el tiempo real no funciona".
     */
    test.setTimeout(120_000);
    const { ctx, page } = await openDashboard(browser);

    await expect
        .poll(async () => page.evaluate(() => document.querySelector('[data-dashboard]')
            .getAttributeNames().filter((n) => n.startsWith('wire:poll'))), { timeout: 30_000 })
        .toEqual([]);

    const wired = await page.evaluate((publicId) => {
        const el = document.querySelector('[data-dashboard]');
        const id = el?.closest('[wire\\:id]')?.getAttribute('wire:id') ?? el?.getAttribute('wire:id');
        if (!window.Echo || !id) return false;
        // '.stats.updated' con punto inicial: el evento usa broadcastAs().
        window.Echo.private(`survey.${publicId}.admin`)
            .listen('.stats.updated', () => window.Livewire.find(id).call('refreshStats'));
        return true;
    }, survey.public_id);
    expect(wired, 'debe existir window.Echo y el id del componente').toBeTruthy();

    const completed = statValue(page, 'Respuestas completadas');
    const before = Number((await completed.innerText()).trim());

    const api = await playwright.request.newContext({ baseURL: 'http://127.0.0.1:8000' });
    await submitAsNewParticipant(api);

    await expect(completed).toHaveText(String(before + 1), { timeout: 30_000 });

    await page.screenshot({ path: resolve(SHOTS, 'ca08-03-con-puente-manual.png'), fullPage: true });
    await api.dispose();
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
