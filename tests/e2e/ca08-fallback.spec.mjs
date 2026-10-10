import { test, expect } from '@playwright/test';
import { mkdirSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { loadFixture, adminContext, statValue, ROOT } from './support/fixture.mjs';
import { observarDashboard, leerDom, enviarRespuesta, esperarCambio, reverbVivo } from './support/realtime.mjs';

/*
 * ============================================================================
 * CA-08.4 — EL RESPALDO OBLIGATORIO DE D-02, con Reverb REALMENTE APAGADO
 * ============================================================================
 *
 * D-02 declara el respaldo `wire:poll` "obligatorio, no opcional: es lo que
 * garantiza CA-08 si Reverb no arranca en el entorno de destino".
 *
 * En la Fase 6 este respaldo FUNCIONABA y era lo unico que funcionaba. En la
 * Fase 6c el resultado se ha INVERTIDO, y por eso este fichero se reescribe:
 *
 *   · El WebSocket ya refresca el dashboard (CA-08.2 lo demuestra).
 *   · El sondeo YA NO REFRESCA NADA.  <-- DEF-05, defecto nuevo
 *
 * ---------------------------- DEF-05, MEDIDO ----------------------------
 * La CSP que introdujo la Fase 7b es `script-src 'self'` SIN 'unsafe-eval'.
 * El build normal de Livewire evalua las expresiones de las directivas con
 * `new Function()`. Resultado: el temporizador de `wire:poll` SI salta (se ven
 * errores en consola exactamente cada 5/10/15 s, al ritmo del intervalo), pero
 * la expresion `refreshStats` nunca llega a evaluarse y NO SE EMITE NINGUNA
 * PETICION.
 *
 * Causa establecida por EXPERIMENTO CONTROLADO, no por conjetura
 * (`support/csp-experiment.mjs`), cambiando una sola variable:
 *     CSP real de la aplicacion   -> 0 sondeos en 30 s, 16 errores de eval
 *     misma CSP + 'unsafe-eval'   -> 7 sondeos en 30 s,  0 errores de eval
 *
 * CONSECUENCIA: el "suelo de sondeo" que anadio la correccion de DEF-01 es
 * COSMETICO. El atributo esta en el DOM, se ve al inspeccionar, y no ejecuta
 * nada. Una comprobacion que solo mire el DOM lo daria por bueno; por eso
 * aqui se comprueba el EFECTO y no la presencia del atributo.
 * ------------------------------------------------------------------------
 *
 * REQUISITO DE EJECUCION: Reverb debe estar PARADO.
 *     tools/f6/services.sh stop && (arrancar solo la aplicacion)
 *     npx playwright test tests/e2e/ca08-fallback.spec.mjs
 *
 * Las pruebas se saltan solas si detectan el 8080 vivo: un respaldo
 * "verificado" mientras el primario funciona no esta verificado.
 */

const fx = loadFixture();
const survey = fx.surveys.active;

const SHOTS = resolve(ROOT, 'storage/app/screenshots/f6c');
const DATOS = resolve(ROOT, 'storage/app/f6c');
mkdirSync(SHOTS, { recursive: true });
mkdirSync(DATOS, { recursive: true });

test('CA-08.4a · con Reverb caido el dashboard declara el estado con honestidad', async ({ browser }) => {
    test.setTimeout(90_000);
    test.skip(await reverbVivo(), 'Reverb sigue en pie: pare el difusor para ejercer el respaldo');

    const ctx = await adminContext(browser, 'admin', { viewport: { width: 1280, height: 900 } });
    const page = await ctx.newPage();

    await page.goto(`/admin/encuestas/${survey.id}/resultados`);
    await expect(page.locator('[data-dashboard]')).toBeVisible();

    // Sin socket se queda en el sondeo mas agresivo y lo DICE.
    await expect.poll(async () => (await leerDom(page)).poll, { timeout: 20_000 })
        .toEqual(['wire:poll.5s']);
    await expect(page.getByText(/tiempo real no disponible/i)).toBeVisible();

    await page.screenshot({ path: resolve(SHOTS, 'ca08-04-degradado-honesto.png'), fullPage: true });
    await ctx.close();
});

test('CA-08.4b · DEFECTO DEF-05: con Reverb caido el sondeo NO refresca el dashboard', async ({ browser, playwright }) => {
    /*
     * test.fail(): se declara que esta prueba DEBE fallar mientras DEF-05 siga
     * abierto. Es el mismo criterio que se aplico a DEF-01 en la Fase 6: el
     * defecto se deja escrito y EJECUTANDOSE, de modo que el dia que alguien
     * corrija la CSP (o sirva el build CSP-safe de Livewire) la prueba pasara
     * y Playwright avisara de que la anotacion sobra.
     *
     * La espera previa de 70 s NO es relleno: con Reverb caido, pusher-js
     * reintenta y cada reintento provoca un `setConnectionState` que, por ser
     * una ida y vuelta a Livewire, re-renderiza el componente y refresca los
     * numeros DE REBOTE. Eso enmascara el fallo durante el primer minuto.
     * Dejando crecer la espera de reintento se observa el comportamiento real
     * de una sesion larga, que es el escenario de un evento de verdad.
     */
    test.fail(true, 'DEF-05: la CSP sin unsafe-eval impide que wire:poll ejecute refreshStats');
    test.setTimeout(240_000);
    test.skip(await reverbVivo(), 'Reverb sigue en pie: pare el difusor para ejercer el respaldo');

    const ctx = await adminContext(browser, 'admin', { viewport: { width: 1280, height: 900 } });
    const page = await ctx.newPage();
    const obs = observarDashboard(page);

    let navegaciones = 0;
    page.on('load', () => { navegaciones += 1; });

    await page.goto(`/admin/encuestas/${survey.id}/resultados`);
    await expect(page.locator('[data-dashboard]')).toBeVisible();

    // Se deja envejecer la sesion para que los reintentos de Echo se espacien.
    await page.waitForTimeout(70_000);

    const completadas = statValue(page, 'Respuestas completadas');
    const antes = (await completadas.innerText()).trim();
    const marca = obs.marca();
    const navsAntes = navegaciones;

    const api = await playwright.request.newContext({ baseURL: 'http://127.0.0.1:8000' });
    expect([302, 303], 'el envio debe guardarse aunque Reverb este caido (D-02)')
        .toContain(await enviarRespuesta(api, survey));

    const cambio = await esperarCambio(page, completadas, antes, 60_000);

    const diagnostico = {
        estado_dom: await leerDom(page),
        contador_antes: antes,
        contador_despues: cambio.valor,
        cambio: cambio.cambio,
        espera_ms: cambio.ms,
        peticiones_tras_envio: obs.resumen(marca),
        sondeos_observados: obs.sondeos(marca),
        errores_csp_total: obs.erroresCsp.length,
        muestra_error_csp: obs.erroresCsp.slice(0, 2),
    };
    writeFileSync(resolve(DATOS, 'def05-respaldo-muerto.json'), JSON.stringify(diagnostico, null, 2));
    console.log('[CA-08.4b] DEF-05:', JSON.stringify(diagnostico, null, 2));

    await page.screenshot({ path: resolve(SHOTS, 'ca08-05-respaldo-no-refresca.png'), fullPage: true });

    // Lo que D-02 EXIGE y hoy no se cumple:
    expect(cambio.cambio, 'el respaldo debe refrescar el dashboard con Reverb caido').toBeTruthy();
    expect(Number(cambio.valor)).toBe(Number(antes) + 1);
    expect(navegaciones, 'sin recargar la pagina').toBe(navsAntes);

    await api.dispose();
    await ctx.close();
});

test('CA-08.4c · evidencia de DEF-05: el temporizador salta pero no sale ni una peticion', async ({ browser }) => {
    /*
     * Prueba POSITIVA del mecanismo del defecto, separada de la anterior para
     * que quede verde y documente el hecho medido aunque CA-08.4b este marcada
     * como fallo esperado. Comprueba la disociacion exacta:
     *   · errores de evaluacion por CSP  > 0   (el temporizador SI salta)
     *   · peticiones de sondeo           = 0   (pero no se emite nada)
     */
    test.setTimeout(120_000);
    test.skip(await reverbVivo(), 'Reverb sigue en pie: pare el difusor para ejercer el respaldo');

    const ctx = await adminContext(browser, 'admin', { viewport: { width: 1280, height: 900 } });
    const page = await ctx.newPage();
    const obs = observarDashboard(page);

    await page.goto(`/admin/encuestas/${survey.id}/resultados`);
    await expect(page.locator('[data-dashboard]')).toBeVisible();

    const dom = await leerDom(page);
    expect(dom.poll, 'el atributo esta presente en el DOM...').toEqual(['wire:poll.5s']);

    const marca = obs.marca();
    await page.waitForTimeout(32_000);   // con 5 s de intervalo: ~6 tics

    const sondeos = obs.sondeos(marca);
    const errores = obs.erroresCsp.length;
    console.log(`[CA-08.4c] en 32 s -> sondeos emitidos=${sondeos}  errores CSP=${errores}`);
    console.log('[CA-08.4c] peticiones:', JSON.stringify(obs.resumen(marca)));

    writeFileSync(resolve(DATOS, 'def05-temporizador.json'), JSON.stringify({
        ventana_s: 32,
        intervalo_dom: dom.poll,
        sondeos_emitidos: sondeos,
        errores_csp: errores,
        muestra: obs.erroresCsp.slice(0, 3),
        peticiones: obs.resumen(marca),
    }, null, 2));

    expect(errores, 'el temporizador del sondeo SI salta: la CSP aborta la evaluacion').toBeGreaterThan(0);
    expect(sondeos, '...pero NO se emite ninguna peticion de sondeo').toBe(0);

    await ctx.close();
});
