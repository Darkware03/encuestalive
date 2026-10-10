import { test, expect } from '@playwright/test';
import { mkdirSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { loadFixture, adminContext, statValue, ROOT } from './support/fixture.mjs';
import {
    observarDashboard, bloquearRebote, leerDom, enviarRespuesta,
    esperarCambio, reverbVivo,
} from './support/realtime.mjs';

/*
 * ============================================================================
 * CA-08.4 — EL RESPALDO OBLIGATORIO DE D-02, con Reverb REALMENTE APAGADO
 * ============================================================================
 *
 * D-02 declara el respaldo `wire:poll` "obligatorio, no opcional: es lo que
 * garantiza CA-08 si Reverb no arranca en el entorno de destino".
 *
 * HISTORIA DE ESTE FICHERO, EN TRES FASES
 * ---------------------------------------
 *   Fase 6   el respaldo FUNCIONABA y era lo unico que funcionaba.
 *   Fase 6c  resultado INVERTIDO: el WebSocket ya refrescaba, y el sondeo no
 *            emitia ni una peticion.  <-- DEF-05, critico
 *   Fase 6d  DEF-05 corregido con `config/livewire.php: csp_safe => true`.
 *            Livewire sirve `livewire.csp.js`, una compilacion que no usa
 *            `new Function()`, asi que la directiva se evalua SIN relajar la
 *            CSP. Aqui se verifica el EFECTO, no la configuracion.
 *
 * ------------------------- LO QUE SE EXIGE AHORA -------------------------
 * CA-08.4b estuvo marcada `test.fail()` mientras DEF-05 siguio abierto. En la
 * Fase 6d se ha medido que el sondeo vuelve a emitir y a refrescar, de modo
 * que la anotacion SE RETIRA: una prueba que sigue declarada como fallo
 * esperado cuando ya pasa es una mentira en verde, y ademas Playwright la
 * contaria como "passed" ocultando el cambio real de comportamiento.
 *
 * ------------------- EL RIESGO NUEVO DE ATRIBUCION -------------------
 * Con el sondeo otra vez vivo vuelve a ser posible confundir las dos vias, y
 * ahora TAMBIEN en el sentido contrario. Sigue en pie, ademas, la trampa
 * concreta que produjo un verde falso en la Fase 6c: con Reverb caido, cada
 * reintento de Echo invoca `setConnectionState`, que por ser una ida y vuelta
 * a Livewire re-renderiza el componente y mueve los numeros DE REBOTE, sin
 * que el sondeo haya hecho nada.
 *
 * Por eso NO basta con "el contador sube". Se conservan los tres controles:
 *   1. ATRIBUCION POSITIVA   se exige observar un POST con `refreshStats`.
 *   2. EXCLUSION ACTIVA      CA-08.4d aborta todo lo que no sea `refreshStats`
 *                            (incluido `setConnectionState`): si aun asi el
 *                            contador sube, el rebote no ha podido ser.
 *   3. CADENCIA / LATENCIA   el refresco debe llegar dentro del intervalo
 *                            declarado por el DOM, no "en algun momento".
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

const SHOTS = resolve(ROOT, 'storage/app/screenshots/f6d');
const DATOS = resolve(ROOT, 'storage/app/f6d');
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

test('CA-08.4b · CIERRE DE DEF-05: con Reverb caido el sondeo SI refresca el dashboard', async ({ browser, playwright }) => {
    /*
     * LA prueba de la Fase 6d, y la que cierra CA-08 por la via del respaldo.
     *
     * Ya NO lleva test.fail(): se exige que pase.
     *
     * La espera previa de 70 s se CONSERVA, aunque el defecto este corregido, y
     * no es relleno. Con Reverb caido, pusher-js reintenta y cada reintento
     * provoca un `setConnectionState` que refresca los numeros de rebote; eso
     * enmascaraba el fallo durante el primer minuto en la Fase 6c. Manteniendo
     * la misma espera se mide EXACTAMENTE la misma ventana temporal que
     * entonces, que es lo que hace comparables el "antes" y el "despues", y se
     * observa el comportamiento de una sesion larga, que es el escenario de un
     * evento de verdad.
     *
     * La atribucion positiva al sondeo es obligatoria: que el contador suba no
     * basta, porque el rebote tambien lo hace subir.
     */
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
        latencia_ms: cambio.ms,
        peticiones_tras_envio: obs.resumen(marca),
        sondeos_intentados: obs.sondeos(marca),
        sondeos_que_llegaron_al_servidor: obs.sondeosServidos(marca),
        rebotes_observados: obs.rebotes(marca),
        puentes_observados: obs.puentes(marca),
        errores_csp_total: obs.erroresCsp.length,
        muestra_error_csp: obs.erroresCsp.slice(0, 2),
    };
    writeFileSync(resolve(DATOS, 'def05-respaldo-vivo.json'), JSON.stringify(diagnostico, null, 2));
    console.log('[CA-08.4b] cierre DEF-05:', JSON.stringify(diagnostico, null, 2));

    await page.screenshot({ path: resolve(SHOTS, 'ca08-05-respaldo-refresca.png'), fullPage: true });

    // 1. Lo que D-02 EXIGE: el dashboard se actualiza con el primario caido.
    expect(cambio.cambio, 'el respaldo debe refrescar el dashboard con Reverb caido').toBeTruthy();
    expect(Number(cambio.valor)).toBe(Number(antes) + 1);

    /*
     * 2. ATRIBUCION POSITIVA al sondeo: sin esto, el verde no significa nada.
     *    Se exige que la peticion LLEGARA al servidor (`sondeosServidos`), no
     *    solo que el navegador la intentara: un intento que no llega no
     *    refresca nada, y contarlo como prueba seria repetir con otro nombre
     *    el error que DEF-05 dejo al descubierto.
     */
    expect(obs.sondeosServidos(marca), 'debe llegar al servidor al menos un POST refreshStats').toBeGreaterThan(0);

    // 3. El puente del WebSocket no ha podido participar: Reverb esta parado.
    expect(obs.puentes(marca), 'con Reverb caido no puede haber onStatsUpdated').toBe(0);

    // 4. La CSP ya no aborta la evaluacion de la directiva (nucleo de DEF-05).
    expect(obs.erroresCsp.length, 'con csp_safe no debe haber errores de evaluacion').toBe(0);

    // 5. Sin recargar la pagina: es un refresco, no una navegacion.
    expect(navegaciones, 'sin recargar la pagina').toBe(navsAntes);

    await api.dispose();
    await ctx.close();
});

test('CA-08.4c · mecanismo: el temporizador salta Y la peticion sale, sin errores de CSP', async ({ browser }) => {
    /*
     * Prueba POSITIVA del mecanismo, invertida respecto a la Fase 6c.
     *
     * Entonces documentaba la DISOCIACION del defecto:
     *     errores de evaluacion > 0   y   peticiones de sondeo = 0
     * es decir, el temporizador saltaba y la expresion nunca se evaluaba.
     *
     * Ahora debe documentar la CADENA COMPLETA:
     *     errores de evaluacion = 0   y   peticiones de sondeo > 0
     * y ademas a la CADENCIA declarada por el DOM, no "alguna vez": un sondeo
     * que emitiese una sola peticion y se detuviera tambien daria > 0.
     */
    test.setTimeout(120_000);
    test.skip(await reverbVivo(), 'Reverb sigue en pie: pare el difusor para ejercer el respaldo');

    const ctx = await adminContext(browser, 'admin', { viewport: { width: 1280, height: 900 } });
    const page = await ctx.newPage();
    const obs = observarDashboard(page);

    await page.goto(`/admin/encuestas/${survey.id}/resultados`);
    await expect(page.locator('[data-dashboard]')).toBeVisible();

    const dom = await leerDom(page);
    expect(dom.poll, 'el atributo debe estar presente en el DOM...').toEqual(['wire:poll.5s']);

    const marca = obs.marca();
    const VENTANA_S = 32;
    await page.waitForTimeout(VENTANA_S * 1000);   // con 5 s de intervalo: ~6 tics

    const sondeos = obs.sondeos(marca);
    const errores = obs.erroresCsp.length;
    const cadencia = obs.peticiones.slice(marca)
        .filter((p) => p.metodos.includes('refreshStats'))
        .map((p) => p.at);

    console.log(`[CA-08.4c] en ${VENTANA_S} s -> sondeos emitidos=${sondeos}  errores CSP=${errores}`);
    console.log('[CA-08.4c] peticiones:', JSON.stringify(obs.resumen(marca)));

    writeFileSync(resolve(DATOS, 'def05-temporizador.json'), JSON.stringify({
        ventana_s: VENTANA_S,
        intervalo_dom: dom.poll,
        sondeos_emitidos: sondeos,
        errores_csp: errores,
        cadencia_ms: cadencia,
        peticiones: obs.resumen(marca),
    }, null, 2));

    // ...y ahora SI se ejecuta: la expresion se evalua sin `new Function()`.
    expect(errores, 'con csp_safe la CSP ya no aborta la evaluacion').toBe(0);
    expect(sondeos, 'el sondeo debe emitir peticiones de verdad').toBeGreaterThan(0);

    /*
     * CADENCIA SOSTENIDA. Con 5 s de intervalo y 32 s de ventana caben ~6 tics.
     * Se exige un suelo prudente de 4 para no convertir la prueba en una
     * medida de la carga de la maquina, pero lo bastante alto como para que un
     * sondeo que arranca y se para no pase.
     */
    expect(sondeos, 'el sondeo debe sostenerse en el tiempo, no emitir una vez').toBeGreaterThanOrEqual(4);

    await ctx.close();
});

test('CA-08.4d · EXCLUSION: el refresco es del sondeo aunque se corte el rebote de `setConnectionState`', async ({ browser, playwright }) => {
    /*
     * EL CONTROL ESPEJO. Es el que impide repetir, del lado del sondeo, el
     * error de atribucion que esta fase existe para evitar del lado del
     * WebSocket, y el que cubre mi propio tropiezo de la Fase 6c.
     *
     * Se aborta TODA peticion a Livewire cuyo metodo no sea `refreshStats`.
     * Con Reverb caido, lo que se aborta en la practica es `setConnectionState`
     * (el rebote de los reintentos de Echo). Si el contador sube igualmente, el
     * refresco SOLO ha podido pedirlo el sondeo: a nadie mas se le dejo llegar
     * al servidor.
     *
     * Se informa del numero de abortadas en lugar de darlo por supuesto: si
     * fuese 0, el control no habria ejercido nada y el verde no probaria la
     * exclusion. Por eso se exige explicitamente que haya abortado algo.
     */
    test.setTimeout(240_000);
    test.skip(await reverbVivo(), 'Reverb sigue en pie: pare el difusor para ejercer el respaldo');

    const ctx = await adminContext(browser, 'admin', { viewport: { width: 1280, height: 900 } });
    const page = await ctx.newPage();
    const obs = observarDashboard(page);
    const bloqueo = await bloquearRebote(page);

    let navegaciones = 0;
    page.on('load', () => { navegaciones += 1; });

    await page.goto(`/admin/encuestas/${survey.id}/resultados`);
    await expect(page.locator('[data-dashboard]')).toBeVisible();

    // Misma espera de envejecimiento: los reintentos de Echo deben haber
    // ocurrido (y haber sido abortados) antes de medir.
    await page.waitForTimeout(70_000);

    const completadas = statValue(page, 'Respuestas completadas');
    const antes = (await completadas.innerText()).trim();
    const marca = obs.marca();
    const navsAntes = navegaciones;

    const api = await playwright.request.newContext({ baseURL: 'http://127.0.0.1:8000' });
    expect([302, 303]).toContain(await enviarRespuesta(api, survey));

    const cambio = await esperarCambio(page, completadas, antes, 60_000);

    const diagnostico = {
        contador_antes: antes,
        contador_despues: cambio.valor,
        cambio: cambio.cambio,
        latencia_ms: cambio.ms,
        sondeos_intentados: obs.sondeos(marca),
        sondeos_que_llegaron: obs.sondeosServidos(marca),
        peticiones_abortadas_total: bloqueo.total(),
        metodos_abortados: [...new Set(bloqueo.resumen())],
        rebotes_intentados: obs.rebotes(marca),
        rebotes_que_llegaron: obs.rebotesServidos(marca),
        puentes_que_llegaron: obs.puentesServidos(marca),
        errores_csp: obs.erroresCsp.length,
        peticiones_tras_envio: obs.resumen(marca),
    };
    writeFileSync(resolve(DATOS, 'ca08-exclusion-rebote.json'), JSON.stringify(diagnostico, null, 2));
    console.log('[CA-08.4d] exclusion del rebote:', JSON.stringify(diagnostico, null, 2));

    await page.screenshot({ path: resolve(SHOTS, 'ca08-06-exclusion-rebote.png'), fullPage: true });

    // El control debe haber ejercido de verdad.
    expect(bloqueo.total(), 'el bloqueo debe haber abortado algo, si no no prueba nada')
        .toBeGreaterThan(0);

    // Y pese a ello, el dashboard se refresca.
    expect(cambio.cambio, 'el sondeo refresca aun sin el rebote de setConnectionState').toBeTruthy();
    expect(Number(cambio.valor)).toBe(Number(antes) + 1);

    // Atribucion exclusiva: lo unico que LLEGO al servidor fue el sondeo.
    expect(obs.sondeosServidos(marca), 'debe haber llegado al menos un refreshStats').toBeGreaterThan(0);
    expect(obs.rebotesServidos(marca), 'ningun setConnectionState debe haber LLEGADO al servidor').toBe(0);
    expect(obs.puentesServidos(marca), 'ningun onStatsUpdated debe haber llegado').toBe(0);
    expect(navegaciones, 'sin recargar la pagina').toBe(navsAntes);

    await api.dispose();
    await ctx.close();
});
