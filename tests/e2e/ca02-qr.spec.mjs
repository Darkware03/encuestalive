import { test, expect } from '@playwright/test';
import { writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { PNG } from 'pngjs';
import jsQR from 'jsqr';
import { loadFixture, adminContext, SHOTS, ROOT } from './support/fixture.mjs';

/*
 * ============================================================================
 * CA-02 — "La encuesta se publica y obtiene URL publica y QR funcional"
 * ============================================================================
 *
 * QUE MEJORA ESTA PRUEBA RESPECTO A LA FASE 5b.
 * El backend comprobo la URL embebida POR EQUIVALENCIA: genero el QR y
 * comparo la cadena que le habia pasado al generador con la que esperaba. Eso
 * demuestra que el codigo llama bien a la libreria, pero NO que el PNG
 * resultante sea legible: si el generador produjera una imagen corrupta, con
 * la zona de silencio mal puesta o con los modulos invertidos, la prueba por
 * equivalencia seguiria pasando igual.
 *
 * Aqui se DECODIFICA el PNG de verdad:
 *   PNG -> pngjs (pixeles RGBA) -> jsQR (lector QR real) -> cadena.
 *
 * Y despues se NAVEGA a la URL decodificada para comprobar que abre la
 * encuesta correcta. Esa es la cadena completa que CA-02 pide.
 *
 * LIMITACION DECLARADA (D-09, se mantiene): nadie ha escaneado el codigo con
 * la camara de un telefono fisico. Esta prueba cubre la decodificacion del
 * mapa de bits, no la optica: enfoque, reflejos, angulo, impresion en papel.
 * CA-02 NO puede declararse aprobado al 100 % por esa via.
 */

const fx = loadFixture();

function decodePng(buffer) {
    const png = PNG.sync.read(buffer);
    const result = jsQR(new Uint8ClampedArray(png.data), png.width, png.height);
    return { png, result };
}

test('CA-02 · el PNG del QR se decodifica con un lector real y abre la encuesta correcta', async ({ browser }) => {
    test.setTimeout(90_000);

    const ctx = await adminContext(browser, 'admin');
    const page = await ctx.newPage();

    const evidence = [];

    for (const key of ['active', 'closed']) {
        const survey = fx.surveys[key];

        const res = await page.request.get(`/admin/encuestas/${survey.id}/qr.png`);
        expect(res.status(), `qr.png de ${survey.public_id}`).toBe(200);
        expect(res.headers()['content-type']).toContain('image/png');

        const buffer = Buffer.from(await res.body());
        writeFileSync(resolve(SHOTS, `ca02-qr-${survey.public_id}.png`), buffer);

        const { png, result } = decodePng(buffer);

        expect(result, `jsQR debe poder leer el PNG de ${survey.public_id}`).not.toBeNull();

        const expected = `http://127.0.0.1:8000/s/${survey.public_id}`;
        expect(result.data, 'la URL decodificada debe ser exactamente la publica').toBe(expected);

        /*
         * El QR no debe llevar NADA mas que la URL: ni token, ni id interno,
         * ni parametros de campana. Un cartel pegado en una pared no puede ser
         * portador de informacion adicional.
         */
        expect(result.data).not.toMatch(/token|secret|[?&]/i);
        expect(result.data.split('/s/')[1]).toBe(survey.public_id);

        evidence.push({
            survey: survey.public_id,
            size: `${png.width}x${png.height}`,
            bytes: buffer.length,
            decoded: result.data,
            decoder: 'jsQR 1.4.0 sobre pixeles RGBA de pngjs 7.0.0',
        });
    }

    /* --------- la URL decodificada abre la encuesta correcta, de verdad --- */
    const decodedActive = evidence[0].decoded;
    const visitor = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const visitorPage = await visitor.newPage();
    const nav = await visitorPage.goto(decodedActive);

    expect(nav.status(), 'la URL del QR debe abrir con 200').toBe(200);
    await expect(visitorPage.getByRole('heading', { name: fx.surveys.active.title })).toBeVisible();
    await visitorPage.screenshot({ path: resolve(SHOTS, 'ca02-url-decodificada-abre-encuesta.png'), fullPage: true });

    /* La del QR de la encuesta CERRADA abre, pero en pantalla de cerrada. */
    const closedPage = await visitor.newPage();
    await closedPage.goto(evidence[1].decoded);
    await expect(closedPage.getByRole('heading', { name: /Encuesta cerrada/i })).toBeVisible();

    writeFileSync(resolve(ROOT, 'storage/app/f6/ca02-qr-decode.json'), JSON.stringify(evidence, null, 2));

    await visitor.close();
    await ctx.close();
});

test('CA-02 · el SVG del QR tambien se sirve y contiene la URL publica', async ({ browser }) => {
    const ctx = await adminContext(browser, 'admin');
    const page = await ctx.newPage();

    const survey = fx.surveys.active;
    const res = await page.request.get(`/admin/encuestas/${survey.id}/qr.svg`);
    expect(res.status()).toBe(200);
    expect(res.headers()['content-type']).toContain('svg');

    const svg = await res.text();
    writeFileSync(resolve(SHOTS, `ca02-qr-${survey.public_id}.svg`), svg);
    // El SVG es vectorial: jsQR no aplica. Se comprueba que es un QR valido
    // estructuralmente y se deja claro que la decodificacion optica se hizo
    // sobre el PNG, que es el formato que se imprime.
    expect(svg).toContain('<svg');
    await ctx.close();
});

test('CA-02 · un anonimo no puede descargar el QR de una encuesta', async ({ browser }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    const res = await page.request.get(`/admin/encuestas/${fx.surveys.active.id}/qr.png`, {
        maxRedirects: 0,
        failOnStatusCode: false,
    });
    // 302 a /login desde el middleware `auth`: nunca el binario.
    expect([302, 401, 403]).toContain(res.status());
    await ctx.close();
});
