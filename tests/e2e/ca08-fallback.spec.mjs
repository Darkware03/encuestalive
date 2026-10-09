import { test, expect } from '@playwright/test';
import { resolve } from 'node:path';
import { loadFixture, adminContext, statValue, SHOTS } from './support/fixture.mjs';

/*
 * ============================================================================
 * CA-08.4 — el respaldo obligatorio de D-02, con Reverb REALMENTE APAGADO
 * ============================================================================
 *
 * D-02 declara el respaldo `wire:poll` (5 s) "obligatorio, no opcional: es lo
 * que garantiza CA-08 si Reverb no arranca en el entorno de destino".
 *
 * Esta prueba comprueba esa promesa en la unica condicion en que significa
 * algo: con el difusor CAIDO. Es complementaria de ca08-realtime.spec.mjs, que
 * cubre el camino con Reverb vivo (y donde DEF-01 lo rompe).
 *
 * REQUISITO DE EJECUCION: Reverb debe estar PARADO.
 *     tools/f6/services.sh stop
 *     php -S ... (solo la aplicacion)   # o: pkill -f "artisan reverb:start"
 *     npx playwright test tests/e2e/ca08-fallback.spec.mjs
 *
 * La prueba se asegura por si misma: si detecta que el puerto 8080 responde,
 * se SALTA en lugar de pasar por la razon equivocada. Un respaldo "verificado"
 * mientras el primario funciona no esta verificado.
 */

const fx = loadFixture();
const survey = fx.surveys.active;

async function reverbVivo() {
    try {
        const res = await fetch('http://127.0.0.1:8080/app/x', { signal: AbortSignal.timeout(2500) });
        return res.status > 0;
    } catch {
        return false;
    }
}

test('CA-08.4 · con Reverb caido, wire:poll refresca el dashboard sin recargar', async ({ browser, playwright }) => {
    test.setTimeout(120_000);

    test.skip(await reverbVivo(), 'Reverb sigue en pie: pare el difusor para ejercer el respaldo');

    const ctx = await adminContext(browser, 'admin', { viewport: { width: 1280, height: 900 } });
    const page = await ctx.newPage();

    let navegaciones = 0;
    page.on('load', () => { navegaciones += 1; });

    await page.goto(`/admin/encuestas/${survey.id}/resultados`);
    const dashboard = page.locator('[data-dashboard]');
    await expect(dashboard).toBeVisible();

    /*
     * 1. Sin socket, el componente debe quedarse en `degraded` y PINTAR el
     *    sondeo. Si no hubiera wire:poll aqui, el dashboard estaria mudo y
     *    CA-08 no se cumpliria por ninguna via.
     */
    await expect
        .poll(async () => dashboard.evaluate((el) =>
            el.getAttributeNames().filter((n) => n.startsWith('wire:poll'))), { timeout: 20_000 })
        .toEqual(['wire:poll.5s']);

    // 2. Y lo dice con honestidad, sin prometer tiempo real.
    await expect(page.getByText(/tiempo real no disponible/i)).toBeVisible();

    const navegacionesTrasCargar = navegaciones;
    const completadas = statValue(page, 'Respuestas completadas');
    const antes = Number((await completadas.innerText()).trim());

    await page.screenshot({ path: resolve(SHOTS, 'ca08-04-respaldo-degradado.png'), fullPage: true });

    /* 3. Un participante real envia mientras el difusor sigue caido. */
    const api = await playwright.request.newContext({ baseURL: 'http://127.0.0.1:8000' });
    const html = await (await api.get(`/s/${survey.public_id}`)).text();
    const token = html.match(/name="_token" value="([^"]+)"/)?.[1];
    const single = survey.questions.find((q) => q.type === 'single_choice');
    const multi = survey.questions.find((q) => q.type === 'multiple_choice');

    const envio = await api.post(`/s/${survey.public_id}/enviar`, {
        form: {
            _token: token,
            [`q[${single.id}]`]: String(single.options[0].id),
            [`q[${multi.id}][]`]: String(multi.options[0].id),
        },
        maxRedirects: 0,
        failOnStatusCode: false,
    });
    expect([302, 303], 'el envio debe guardarse aunque Reverb este caido (D-02)').toContain(envio.status());

    /* 4. El contador sube SOLO, por sondeo, sin recargar la pagina. */
    await expect(completadas).toHaveText(String(antes + 1), { timeout: 30_000 });
    expect(navegaciones, 'el dashboard no debe haberse recargado').toBe(navegacionesTrasCargar);

    await page.screenshot({ path: resolve(SHOTS, 'ca08-05-respaldo-actualizado.png'), fullPage: true });

    await api.dispose();
    await ctx.close();
});
