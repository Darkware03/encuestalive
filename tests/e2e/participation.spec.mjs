import { test, expect } from '@playwright/test';
import { resolve } from 'node:path';
import { loadFixture, adminContext, fillValidAnswers, submitSurvey, SHOTS } from './support/fixture.mjs';

/*
 * ============================================================================
 * Recorridos criticos del participante — CA-03, CA-04, CA-05, CA-06, CA-07, CA-13
 * ============================================================================
 *
 * Todo contra el servidor REAL (127.0.0.1:8000) y con navegador REAL.
 *
 * Donde una comprobacion pertenece al SERVIDOR (rechazo de una encuesta
 * cerrada, limites de seleccion, pertenencia de opciones), se ejerce por HTTP
 * directo ademas de por interfaz: ocultar un boton no cierra nada, y la unica
 * prueba que vale es la que se salta la interfaz igual que lo haria `curl`.
 */

const fx = loadFixture();
const active = fx.surveys.active;
const closed = fx.surveys.closed;
const paused = fx.surveys.paused;

const single = active.questions.find((q) => q.type === 'single_choice');
const multi = active.questions.find((q) => q.type === 'multiple_choice');
const free = active.questions.find((q) => q.type === 'free_text');

/*
 * Token CSRF para un contexto de peticiones.
 *
 * SIEMPRE se toma de la pagina de la encuesta ACTIVA, nunca de la que se va a
 * atacar. Motivo medido: las pantallas de encuesta cerrada y pausada no pintan
 * formulario, y el layout del participante NO lleva <meta name="csrf-token">,
 * asi que en esas paginas no hay ningun token que extraer.
 *
 * Mi primera version lo leia de la pagina de destino y obtenia `undefined`: el
 * POST a la encuesta pausada respondia 419 (token invalido) en lugar de 410.
 * Habria sido facil apuntar "rechazado" y dar CA-13 por bueno, pero 419 NO
 * demuestra la puerta de estado: demuestra que me falto el token. El 410 solo
 * se alcanza con una peticion por lo demas VALIDA.
 *
 * Funciona porque el token de CSRF vive en la sesion de Laravel (cookie con
 * Path=/), no en la ruta: el mismo token sirve para cualquier POST del sitio.
 */
async function csrf(request) {
    const html = await (await request.get(`/s/${active.public_id}`)).text();
    const token = html.match(/name="_token" value="([^"]+)"/)?.[1];
    expect(token, 'no se pudo obtener un token CSRF valido').toBeTruthy();
    return token;
}

/* ======================================================= CA-03 · movil 390 */

test('CA-03 · participacion anonima completa en movil de 390 px', async ({ browser }) => {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: false });
    const page = await ctx.newPage();

    await page.goto(`/s/${active.public_id}`);

    // Sin cuenta: en ninguna parte de la pantalla se pide identificarse.
    await expect(page.getByRole('heading', { name: active.title })).toBeVisible();
    await expect(page.locator('input[type=email], input[type=password]')).toHaveCount(0);
    await page.screenshot({ path: resolve(SHOTS, 'ca03-01-movil-390-formulario.png'), fullPage: true });

    await fillValidAnswers(page, active, { text: 'Todo correcto, gracias.' });
    await submitSurvey(page);

    // CA-07: confirmacion explicita con recibo.
    await expect(page.getByRole('heading', { name: 'Respuesta enviada' })).toBeVisible({ timeout: 20_000 });
    await expect(page.getByText(/Tu recibo:/)).toBeVisible();
    const receipt = await page.locator('code').first().innerText();
    expect(receipt).toMatch(/^R-[0-9A-F]{8}$/);

    await page.screenshot({ path: resolve(SHOTS, 'ca03-02-movil-390-confirmacion.png'), fullPage: true });
    await ctx.close();
});

/* ============================================= CA-07 · duplicado y reenvio */

test('CA-07 · la misma sesion no puede enviar dos veces', async ({ browser }) => {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const page = await ctx.newPage();

    await page.goto(`/s/${active.public_id}`);
    // El token se captura ANTES de enviar: despues del envio la pantalla de
    // confirmacion ya no tiene formulario ni meta, y no habria de donde sacarlo.
    const token = await page.locator('input[name=_token]').first().inputValue();
    await fillValidAnswers(page, active, { text: 'Primer envio.' });
    await submitSurvey(page);
    await expect(page.getByRole('heading', { name: 'Respuesta enviada' })).toBeVisible({ timeout: 20_000 });

    // Al recargar, el servidor decide la pantalla: no vuelve a ofrecer el
    // formulario (seria invitar a rellenarlo para despues rechazarlo).
    await page.reload();
    await expect(page.getByRole('heading', { name: 'Respuesta enviada' })).toBeVisible();
    await expect(page.locator('[data-survey-form]')).toHaveCount(0);

    // Y por HTTP directo, con la MISMA cookie, el reenvio es idempotente:
    // 200 con duplicate=true, nunca un 409 ni una segunda fila.
    const res = await page.request.post(`/s/${active.public_id}/responses`, {
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token },
        data: {
            submission_uuid: '11111111-2222-4333-8444-555555555555',
            answers: [{ question_id: single.id, option_ids: [single.options[0].id] }],
        },
        failOnStatusCode: false,
    });
    expect(res.status()).toBe(200);
    expect((await res.json()).duplicate, 'el reenvio debe ser idempotente').toBe(true);

    await ctx.close();
});

test('CA-07 · reconexion: reenviar el mismo submission_uuid no duplica', async ({ playwright }) => {
    const api = await playwright.request.newContext({ baseURL: 'http://127.0.0.1:8000' });
    const token = await csrf(api);
    /*
     * UUID ALEATORIO POR EJECUCION, no una constante.
     *
     * Con un uuid fijo la prueba solo pasaba la primera vez: la base de datos
     * E2E persiste entre ejecuciones y `findExisting()` busca tambien por
     * `submission_uuid`, asi que en la segunda pasada el envio "primero" ya
     * encontraba el de la pasada anterior y volvia con duplicate=true.
     *
     * El fallo era de la prueba, no del producto: la idempotencia hacia
     * exactamente lo que debe. Una prueba que solo pasa con la base de datos
     * recien creada es una prueba que acabara mintiendo.
     */
    const uuid = crypto.randomUUID();

    const payload = {
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token },
        data: { submission_uuid: uuid, answers: [
            { question_id: single.id, option_ids: [single.options[1].id] },
            { question_id: multi.id, option_ids: [multi.options[0].id] },
        ] },
        failOnStatusCode: false,
    };

    const first = await api.post(`/s/${active.public_id}/responses`, payload);
    expect(first.status()).toBe(200);
    const firstBody = await first.json();
    expect(firstBody.duplicate).toBe(false);

    // Mismo uuid, como haria el reintento automatico tras un corte de red.
    const retry = await api.post(`/s/${active.public_id}/responses`, payload);
    expect(retry.status()).toBe(200);
    const retryBody = await retry.json();
    expect(retryBody.duplicate, 'el reintento debe devolver el recibo original').toBe(true);
    // Mismo recibo: es LA prueba de que no se creo una segunda respuesta.
    expect(retryBody.receipt_id).toBe(firstBody.receipt_id);

    await api.dispose();
});

/* ====================================================== CA-04 · unica */

test('CA-04 · seleccion unica rechaza en el SERVIDOR dos opciones a la vez', async ({ playwright }) => {
    const api = await playwright.request.newContext({ baseURL: 'http://127.0.0.1:8000' });
    const token = await csrf(api);

    const res = await api.post(`/s/${active.public_id}/responses`, {
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token },
        data: {
            submission_uuid: crypto.randomUUID(),
            answers: [
                { question_id: single.id, option_ids: [single.options[0].id, single.options[1].id] },
                { question_id: multi.id, option_ids: [multi.options[0].id] },
            ],
        },
        failOnStatusCode: false,
    });

    expect(res.status(), 'dos opciones en una pregunta de seleccion unica').toBe(422);
    expect(JSON.stringify(await res.json())).toMatch(/exactamente una opcion/i);
    await api.dispose();
});

test('CA-04 · una opcion de otra pregunta se rechaza (no se descarta en silencio)', async ({ playwright }) => {
    const api = await playwright.request.newContext({ baseURL: 'http://127.0.0.1:8000' });
    const token = await csrf(api);

    const res = await api.post(`/s/${active.public_id}/responses`, {
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token },
        data: {
            submission_uuid: crypto.randomUUID(),
            // Opcion que pertenece a la pregunta multiple, enviada en la unica.
            answers: [{ question_id: single.id, option_ids: [multi.options[0].id] }],
        },
        failOnStatusCode: false,
    });

    expect(res.status()).toBe(422);
    expect(JSON.stringify(await res.json())).toMatch(/no pertenece/i);
    await api.dispose();
});

/* ==================================================== CA-05 · multiple */

test('CA-05 · seleccion multiple valida los limites configurados (min 1, max 2)', async ({ playwright }) => {
    const api = await playwright.request.newContext({ baseURL: 'http://127.0.0.1:8000' });
    const token = await csrf(api);

    const attempt = async (optionIds) => {
        const res = await api.post(`/s/${active.public_id}/responses`, {
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token },
            data: {
                submission_uuid: crypto.randomUUID(),
                answers: [
                    { question_id: single.id, option_ids: [single.options[0].id] },
                    { question_id: multi.id, option_ids: optionIds },
                ],
            },
            failOnStatusCode: false,
        });
        return { status: res.status(), body: JSON.stringify(await res.json().catch(() => ({}))) };
    };

    // POR ENCIMA del maximo: 3 opciones donde el maximo es 2.
    const tooMany = await attempt(multi.options.slice(0, 3).map((o) => o.id));
    expect(tooMany.status, '3 opciones con max_selections=2').toBe(422);
    expect(tooMany.body).toMatch(/como maximo 2/i);

    // POR DEBAJO del minimo: ninguna, siendo obligatoria.
    const tooFew = await attempt([]);
    expect(tooFew.status, '0 opciones con min_selections=1 y obligatoria').toBe(422);

    // La misma opcion repetida no cuenta doble: se rechaza explicitamente.
    const dup = await attempt([multi.options[0].id, multi.options[0].id]);
    expect(dup.status, 'opcion duplicada').toBe(422);
    expect(dup.body).toMatch(/misma opcion/i);

    await api.dispose();
});

/* ==================================================== CA-06 · texto libre */

test('CA-06 · el texto libre se almacena en crudo y se RENDERIZA escapado', async ({ browser, playwright }) => {
    const payload = '<script>window.__xss=1</script><img src=x onerror="window.__xss=2">';

    const api = await playwright.request.newContext({ baseURL: 'http://127.0.0.1:8000' });
    const token = await csrf(api);
    const res = await api.post(`/s/${active.public_id}/responses`, {
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token },
        data: {
            submission_uuid: crypto.randomUUID(),
            answers: [
                { question_id: single.id, option_ids: [single.options[0].id] },
                { question_id: multi.id, option_ids: [multi.options[0].id] },
                { question_id: free.id, text: payload },
            ],
        },
        failOnStatusCode: false,
    });
    expect(res.status(), 'el texto malicioso debe ACEPTARSE, no rechazarse').toBe(200);

    // Y ahora el dashboard del admin, que es donde ese texto se pinta.
    const ctx = await adminContext(browser, 'admin');
    const page = await ctx.newPage();
    let dialogs = 0;
    page.on('dialog', async (d) => { dialogs += 1; await d.dismiss(); });

    await page.goto(`/admin/encuestas/${active.id}/resultados`);

    // El texto aparece como TEXTO, literal, incluidas las etiquetas.
    await expect(page.getByText(payload, { exact: false }).first()).toBeVisible({ timeout: 20_000 });

    // No se ejecuto nada.
    expect(await page.evaluate(() => window.__xss)).toBeUndefined();
    expect(dialogs).toBe(0);
    // Y no se inyecto ningun nodo real.
    expect(await page.locator('img[src="x"]').count()).toBe(0);

    await page.screenshot({ path: resolve(SHOTS, 'ca06-texto-libre-escapado.png'), fullPage: true });
    await ctx.close();
    await api.dispose();
});

/* ============================================= CA-13 · cerrada y pausada */

test('CA-13 · una encuesta cerrada rechaza en el servidor (410), no solo en la interfaz', async ({ browser, playwright }) => {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const page = await ctx.newPage();

    await page.goto(`/s/${closed.public_id}`);
    await expect(page.getByRole('heading', { name: /Encuesta cerrada/i })).toBeVisible();
    // No hay formulario que rellenar.
    await expect(page.locator('[data-survey-form]')).toHaveCount(0);
    await page.screenshot({ path: resolve(SHOTS, 'ca13-encuesta-cerrada.png'), fullPage: true });

    // La prueba de verdad: saltandose la interfaz por completo.
    const api = await playwright.request.newContext({ baseURL: 'http://127.0.0.1:8000' });
    const token = await csrf(api);
    const res = await api.post(`/s/${closed.public_id}/responses`, {
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token },
        data: {
            submission_uuid: crypto.randomUUID(),
            answers: [{ question_id: closed.questions[0].id, option_ids: [closed.questions[0].options[0].id] }],
        },
        failOnStatusCode: false,
    });
    expect(res.status(), 'POST a encuesta cerrada').toBe(410);

    await api.dispose();
    await ctx.close();
});

test('CA-13 · una encuesta pausada tambien rechaza (410) y lo dice', async ({ browser, playwright }) => {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const page = await ctx.newPage();
    await page.goto(`/s/${paused.public_id}`);
    await expect(page.getByRole('heading', { name: /Encuesta pausada/i })).toBeVisible();
    await page.screenshot({ path: resolve(SHOTS, 'ca13-encuesta-pausada.png'), fullPage: true });

    const api = await playwright.request.newContext({ baseURL: 'http://127.0.0.1:8000' });
    const token = await csrf(api);
    const res = await api.post(`/s/${paused.public_id}/responses`, {
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token },
        data: {
            submission_uuid: crypto.randomUUID(),
            answers: [{ question_id: paused.questions[0].id, option_ids: [paused.questions[0].options[0].id] }],
        },
        failOnStatusCode: false,
    });
    expect(res.status()).toBe(410);
    expect((await res.json()).status).toBe('paused');

    // El heartbeat tambien se corta: el cliente debe dejar de latir.
    const hb = await api.post(`/s/${paused.public_id}/heartbeat`, {
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token },
        failOnStatusCode: false,
    });
    expect(hb.status()).toBe(410);

    await api.dispose();
    await ctx.close();
});
