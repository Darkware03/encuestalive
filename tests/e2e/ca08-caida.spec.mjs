import { test, expect } from '@playwright/test';
import { execSync, spawn } from 'node:child_process';
import { mkdirSync, writeFileSync, openSync } from 'node:fs';
import { resolve } from 'node:path';
import { loadFixture, adminContext, statValue, ROOT } from './support/fixture.mjs';
import { observarDashboard, leerDom, enviarRespuesta, esperarCambio, reverbVivo } from './support/realtime.mjs';

/*
 * ============================================================================
 * CA-08.6 — REVERB SE CAE CON EL USUARIO YA CONECTADO  (@caida)
 * ============================================================================
 *
 * Es el escenario REAL de un evento con mala red, y el que mas importa: no
 * "Reverb no arranco" (eso ya lo cubre ca08-fallback), sino "Reverb estaba
 * bien y se cayo a mitad". La pregunta concreta a responder es:
 *
 *     ¿SE RECUPERA EL SONDEO cuando el socket muere?
 *
 * La correccion de DEF-01 promete que si: al perder el socket el componente
 * pasa a `reconnecting` (10 s) y luego a `degraded` (5 s), intervalos mas
 * agresivos que el suelo de 15 s. Esta prueba verifica las DOS mitades por
 * separado, porque no son la misma cosa:
 *
 *   a) la RECUPERACION DECLARADA: el DOM reacciona a la caida y aprieta el
 *      intervalo de sondeo.                                  -> se espera OK
 *   b) la RECUPERACION EFECTIVA: tras la caida, el dashboard vuelve a
 *      refrescarse de verdad.                                -> DEF-05
 *
 * Que (a) pase y (b) falle es precisamente el hallazgo de la fase: el sistema
 * REACCIONA bien y no SIRVE de nada, porque el sondeo al que recurre no se
 * ejecuta bajo la CSP vigente.
 *
 * ----------------------------- AISLAMIENTO -----------------------------
 * Esta prueba MATA Y REARRANCA un servicio compartido. Por eso esta fuera de
 * la bateria por defecto (etiqueta `@caida`, excluida en playwright.config)
 * y se ejecuta a proposito:
 *
 *     E2E_INCLUIR_CAIDA=1 npx playwright test tests/e2e/ca08-caida.spec.mjs
 *
 * `afterAll` restaura Reverb pase lo que pase, para no dejar el entorno peor
 * de como se lo encontro.
 * -----------------------------------------------------------------------
 */

const fx = loadFixture();
const survey = fx.surveys.active;

const SHOTS = resolve(ROOT, 'storage/app/screenshots/f6c');
const DATOS = resolve(ROOT, 'storage/app/f6c');
mkdirSync(SHOTS, { recursive: true });
mkdirSync(DATOS, { recursive: true });

function pidsReverb() {
    try {
        return execSync("ps -eo pid,args --no-headers | grep 'reverb:start' | grep -v grep | awk '{print $1}'",
            { encoding: 'utf8' }).trim().split('\n').filter(Boolean);
    } catch {
        return [];
    }
}

function matarReverb() {
    for (const pid of pidsReverb()) {
        try { execSync(`kill -9 ${pid}`); } catch { /* ya no estaba */ }
    }
}

function arrancarReverb() {
    if (pidsReverb().length) return;
    const log = openSync(resolve(ROOT, 'storage/app/f6/reverb.log'), 'a');
    const hijo = spawn('php', ['artisan', 'reverb:start', '--host=127.0.0.1', '--port=8080'], {
        cwd: ROOT,
        detached: true,
        stdio: ['ignore', log, log],
        env: {
            ...process.env,
            DB_DATABASE: resolve(ROOT, 'storage/app/f6/e2e.sqlite'),
            BROADCAST_CONNECTION: 'reverb',
            APP_URL: 'http://127.0.0.1:8000',
        },
    });
    hijo.unref();
}

async function esperarReverb(vivoEsperado, intentos = 30) {
    for (let i = 0; i < intentos; i++) {
        if (await reverbVivo() === vivoEsperado) return true;
        await new Promise((r) => setTimeout(r, 1000));
    }
    return false;
}

/*
 * Cada prueba de este fichero EMPIEZA con Reverb vivo, porque su supuesto es
 * "estaba funcionando y se cayo". Sin este `beforeEach`, la primera prueba
 * mata el difusor y la segunda se SALTA sola por no encontrarlo en pie: el
 * caso que mas importa quedaria sin ejecutar y el informe lo daria por
 * cubierto. `afterAll` no sirve para esto: corre al final del FICHERO, no
 * entre pruebas.
 */
test.beforeEach(async () => {
    arrancarReverb();
    await esperarReverb(true);
});

test.afterAll(async () => {
    // Red de seguridad: el entorno se devuelve como estaba, con Reverb vivo.
    arrancarReverb();
    await esperarReverb(true);
});

/*
 * ===================== POR QUE ESTA PRUEBA SE PARTIO EN DOS =====================
 * La primera version comprobaba solo "¿sube el contador tras la caida?" y PASO.
 * Al leer el diagnostico que ella misma dejo escrito, el verde era FALSO:
 *
 *     sondeos_emitidos: 0, puentes_emitidos: 0,
 *     unica peticion: setConnectionState
 *
 * Es decir: el contador se movio, pero NO lo movio el respaldo. Lo movio un
 * efecto colateral de los reintentos de Echo: cada intento de reconexion llama
 * a `setConnectionState`, y como toda ida y vuelta a Livewire re-renderiza el
 * componente, los numeros se refrescan DE REBOTE.
 *
 * Aceptar ese verde habria sido cometer, del lado del sondeo, exactamente el
 * mismo error de atribucion que esta fase existe para evitar del lado del
 * WebSocket. Un refresco solo cuenta como "el respaldo funciona" si lo pidio
 * el respaldo. Por eso ahora se exige ATRIBUCION POSITIVA al sondeo.
 * ===============================================================================
 */

test('CA-08.6a · @caida Reverb cae con el usuario conectado: el dashboard REACCIONA', async ({ browser }) => {
    test.setTimeout(300_000);
    test.skip(!(await reverbVivo()), 'Reverb debe estar EN PIE al empezar: es el supuesto de la prueba');

    const ctx = await adminContext(browser, 'admin', { viewport: { width: 1280, height: 900 } });
    const page = await ctx.newPage();

    let navegaciones = 0;
    page.on('load', () => { navegaciones += 1; });

    await page.goto(`/admin/encuestas/${survey.id}/resultados`);
    await expect(page.locator('[data-dashboard]')).toBeVisible();

    /* ---- 1. Punto de partida: conectado, con el suelo de 15 s ---- */
    await expect.poll(async () => (await leerDom(page)).estado, { timeout: 30_000 }).toBe('connected');
    const domConectado = await leerDom(page);
    expect(domConectado.poll).toEqual(['wire:poll.15s']);
    await page.screenshot({ path: resolve(SHOTS, 'ca08-06-antes-de-la-caida.png'), fullPage: true });

    /* ---- 2. Cae Reverb, con el usuario mirando la pantalla ---- */
    matarReverb();
    expect(await esperarReverb(false), 'Reverb debe quedar realmente caido').toBeTruthy();
    const navsTrasCaida = navegaciones;

    /* ---- 3. RECUPERACION DECLARADA: el DOM reacciona y aprieta el sondeo ---- */
    await expect.poll(
        async () => (await leerDom(page)).estado,
        { timeout: 90_000, message: 'el componente debe salir de connected al morir el socket' },
    ).not.toBe('connected');

    const domCaido = await leerDom(page);
    console.log('[CA-08.6] DOM tras la caida:', JSON.stringify(domCaido));
    await page.screenshot({ path: resolve(SHOTS, 'ca08-07-tras-la-caida.png'), fullPage: true });

    expect(['reconnecting', 'degraded'],
        'el estado debe reflejar la perdida del socket').toContain(domCaido.estado);
    expect(['wire:poll.10s', 'wire:poll.5s'],
        'el intervalo debe APRETARSE respecto al suelo de 15 s').toContain(domCaido.poll[0]);
    expect(navegaciones, 'nada de esto debe requerir recargar la pagina').toBe(navsTrasCaida);

    writeFileSync(resolve(DATOS, 'ca08-caida-reaccion.json'), JSON.stringify({
        dom_conectado: domConectado,
        dom_tras_caida: domCaido,
    }, null, 2));

    await page.screenshot({ path: resolve(SHOTS, 'ca08-07-tras-la-caida.png'), fullPage: true });
    await ctx.close();
});

test('CA-08.6b · @caida DEF-05: tras la caida el SONDEO no recupera el refresco', async ({ browser, playwright }) => {
    /*
     * La mitad que de verdad importa: no "¿cambia algo en pantalla?" sino
     * "¿lo hace el respaldo?". Se exige una llamada `refreshStats` observada.
     *
     * test.fail() mientras DEF-05 siga abierto.
     */
    test.fail(true, 'DEF-05: la CSP sin unsafe-eval impide que wire:poll ejecute refreshStats');
    test.setTimeout(300_000);
    test.skip(!(await reverbVivo()), 'Reverb debe estar EN PIE al empezar: es el supuesto de la prueba');

    const ctx = await adminContext(browser, 'admin', { viewport: { width: 1280, height: 900 } });
    const page = await ctx.newPage();
    const obs = observarDashboard(page);

    await page.goto(`/admin/encuestas/${survey.id}/resultados`);
    await expect(page.locator('[data-dashboard]')).toBeVisible();
    await expect.poll(async () => (await leerDom(page)).estado, { timeout: 30_000 }).toBe('connected');

    matarReverb();
    expect(await esperarReverb(false), 'Reverb debe quedar realmente caido').toBeTruthy();
    await expect.poll(async () => (await leerDom(page)).estado, { timeout: 90_000 }).not.toBe('connected');

    /*
     * Se deja envejecer la sesion: durante el primer minuto los reintentos de
     * Echo refrescan la pantalla de rebote y ENMASCARAN el fallo del sondeo.
     */
    await page.waitForTimeout(70_000);

    const completadas = statValue(page, 'Respuestas completadas');
    const antes = (await completadas.innerText()).trim();
    const marca = obs.marca();

    const api = await playwright.request.newContext({ baseURL: 'http://127.0.0.1:8000' });
    expect([302, 303]).toContain(await enviarRespuesta(api, survey));

    const cambio = await esperarCambio(page, completadas, antes, 60_000);

    const diagnostico = {
        dom_tras_envejecer: await leerDom(page),
        contador_antes: antes,
        contador_despues: cambio.valor,
        cambio_en_pantalla: cambio.cambio,
        espera_ms: cambio.ms,
        peticiones_tras_envio: obs.resumen(marca),
        sondeos_emitidos: obs.sondeos(marca),
        puentes_emitidos: obs.puentes(marca),
        errores_csp: obs.erroresCsp.length,
    };
    writeFileSync(resolve(DATOS, 'ca08-caida-reverb.json'), JSON.stringify(diagnostico, null, 2));
    console.log('[CA-08.6b] diagnostico:', JSON.stringify(diagnostico, null, 2));

    await page.screenshot({ path: resolve(SHOTS, 'ca08-08-tras-envio-con-reverb-caido.png'), fullPage: true });
    await api.dispose();
    await ctx.close();

    /*
     * ATRIBUCION POSITIVA. No basta con que el contador suba: tiene que
     * haberlo pedido el SONDEO. Sin esta linea la prueba pasaria por el
     * rebote de `setConnectionState` y certificaria un respaldo que no existe.
     */
    expect(obs.sondeos(marca),
        'DEF-05: el respaldo debe emitir al menos un refreshStats tras la caida')
        .toBeGreaterThan(0);
    expect(cambio.cambio, 'y el contador debe subir por esa via').toBeTruthy();
});
