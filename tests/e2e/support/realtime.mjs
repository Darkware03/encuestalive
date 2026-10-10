/*
 * ============================================================================
 * Discriminador WEBSOCKET vs SONDEO para la reverificacion de CA-08 (Fase 6c)
 * ============================================================================
 *
 * EL PROBLEMA QUE RESUELVE ESTE FICHERO
 * -------------------------------------
 * Tras la correccion de DEF-01 el sondeo es un SUELO PERMANENTE: con el socket
 * sano el dashboard lleva `wire:poll.15s`. Eso significa que ver subir un
 * contador YA NO DEMUESTRA NADA por si solo: el refresco pudo venir del
 * WebSocket o de un tic del sondeo que casualmente cayo en ese instante.
 * Atribuir al WebSocket un refresco que pudo hacer el sondeo seria exactamente
 * el error que convirtio CA-08 en un falso aprobado durante dos fases.
 *
 * COMO SE DISTINGUEN, SIN AMBIGÜEDAD
 * ----------------------------------
 * Las dos vias llaman a METODOS DISTINTOS del componente Livewire, y cada
 * llamada viaja en el cuerpo JSON de un POST que se puede leer:
 *
 *     wire:poll.15s="refreshStats"   ->  calls[].method == "refreshStats"
 *     puente Echo de realtime.js     ->  calls[].method == "onStatsUpdated"
 *                                        (o "onTextReceived")
 *
 * Se aplican TRES controles independientes, no uno:
 *
 *   1. ATRIBUCION POSITIVA. Se exige observar un POST con `onStatsUpdated`.
 *   2. EXCLUSION ACTIVA. `bloquearSondeo()` ABORTA toda peticion cuyo metodo
 *      sea `refreshStats`. Si el contador sube igual, el sondeo no ha podido
 *      ser: no se le permitio llegar al servidor. Este control es el que hace
 *      la prueba honesta EL DIA QUE EL SONDEO VUELVA A FUNCIONAR; sin el, la
 *      prueba pasaria "gratis" solo porque hoy el sondeo esta roto (DEF-05).
 *   3. LATENCIA. Se mide el tiempo desde el envio hasta el cambio en el DOM y
 *      se exige que sea muy inferior al suelo de 15 s.
 *
 * NOTA SOBRE EL ENDPOINT: la Fase 7b ofusco la ruta de Livewire, que ya no es
 * `/livewire/update` sino `/livewire-<hash>/update`. Por eso se filtra con una
 * expresion regular y no con una cadena fija: una ruta fija no casaria con
 * nada y el discriminador mediria cero en silencio, que es la peor forma de
 * fallar en una herramienta de medida.
 */

export const LIVEWIRE_UPDATE = /\/livewire[^/]*\/update$/;

/** Metodos que indican que el refresco lo pidio el SONDEO. */
export const METODOS_SONDEO = ['refreshStats'];
/** Metodos que indican que el refresco lo pidio el PUENTE del WebSocket. */
export const METODOS_PUENTE = ['onStatsUpdated', 'onTextReceived'];
/*
 * Metodos que NO refrescan por diseno pero que, por ser una ida y vuelta a
 * Livewire, re-renderizan el componente y mueven los numeros DE REBOTE.
 * `setConnectionState` es el caso real que produjo un verde falso en la
 * Fase 6c: con Reverb caido, cada reintento de Echo lo invoca.
 */
export const METODOS_REBOTE = ['setConnectionState'];

/** Extrae los nombres de metodo invocados en un cuerpo de peticion Livewire. */
export function metodosDe(body) {
    try {
        const j = JSON.parse(body ?? '{}');
        return (j.components ?? []).flatMap((c) => (c.calls ?? []).map((k) => k.method));
    } catch {
        return [];
    }
}

/**
 * Registra y clasifica cada POST a Livewire, y los frames del WebSocket.
 * Devuelve un objeto vivo que se puede consultar en cualquier momento.
 */
export function observarDashboard(page) {
    const t0 = Date.now();
    const peticiones = [];
    const frames = [];
    const erroresCsp = [];

    page.on('console', (m) => {
        const t = m.text();
        if (/unsafe-eval|EvalError|Expression Error/i.test(t)) {
            erroresCsp.push({ at: Date.now() - t0, text: t.slice(0, 160) });
        }
    });

    page.on('websocket', (ws) => {
        ws.on('framereceived', (f) => frames.push({ at: Date.now() - t0, payload: String(f.payload) }));
    });

    /*
     * ------------------------------------------------------------------
     * ATENCION: `request` SE EMITE AL INTENTARLO, NO AL LLEGAR AL SERVIDOR
     * ------------------------------------------------------------------
     * Fase 6d. Playwright emite `request` cuando el navegador INICIA la
     * peticion, incluso si despues `page.route()` la ABORTA. Mientras DEF-05
     * estuvo abierto esa diferencia no se notaba: el sondeo no intentaba
     * nada, el contador valia 0, y afirmar "ningun refreshStats llego al
     * servidor" resultaba cierto POR ACCIDENTE.
     *
     * Con el sondeo ya operativo la diferencia es decisiva: en una medida
     * real se observaron 4 intentos y 4 abortados, es decir CERO llegadas,
     * y sin embargo el contador de intentos marcaba 4. Usarlo como prueba de
     * exclusion habria sido medir una cosa y afirmar otra.
     *
     * Por eso cada peticion se marca cuando RECIBE RESPUESTA. La exclusion se
     * comprueba con `sondeosServidos()`, no con `sondeos()`.
     */
    const porPeticion = new Map();

    page.on('request', (req) => {
        if (req.method() !== 'POST') return;
        let pathname;
        try { pathname = new URL(req.url()).pathname; } catch { return; }
        if (!LIVEWIRE_UPDATE.test(pathname)) return;
        const entrada = { at: Date.now() - t0, metodos: metodosDe(req.postData()), servida: false };
        peticiones.push(entrada);
        porPeticion.set(req, entrada);
    });

    // Solo hay respuesta si la peticion NO fue abortada: llego al servidor.
    page.on('response', (res) => {
        const entrada = porPeticion.get(res.request());
        if (entrada) entrada.servida = true;
    });

    const cuenta = (lista, desde = 0) => peticiones
        .slice(desde)
        .filter((p) => p.metodos.some((m) => lista.includes(m))).length;

    const cuentaServidas = (lista, desde = 0) => peticiones
        .slice(desde)
        .filter((p) => p.servida && p.metodos.some((m) => lista.includes(m))).length;

    return {
        peticiones,
        frames,
        erroresCsp,
        marca: () => peticiones.length,
        sondeos: (desde = 0) => cuenta(METODOS_SONDEO, desde),
        puentes: (desde = 0) => cuenta(METODOS_PUENTE, desde),
        rebotes: (desde = 0) => cuenta(METODOS_REBOTE, desde),
        /** Sondeos que de verdad LLEGARON al servidor (no abortados). */
        sondeosServidos: (desde = 0) => cuentaServidas(METODOS_SONDEO, desde),
        /** Puentes que de verdad LLEGARON al servidor (no abortados). */
        puentesServidos: (desde = 0) => cuentaServidas(METODOS_PUENTE, desde),
        /** Rebotes de `setConnectionState` que LLEGARON al servidor. */
        rebotesServidos: (desde = 0) => cuentaServidas(METODOS_REBOTE, desde),
        framesStats: () => frames.filter((f) => f.payload.includes('stats.updated')).length,
        suscrito: () => frames.some((f) => f.payload.includes('subscription_succeeded')),
        resumen: (desde = 0) => peticiones.slice(desde)
            .map((p) => `+${p.at}ms ${p.metodos.join('|') || '(sin calls)'}${p.servida ? '' : ' [ABORTADA]'}`),
    };
}

/**
 * EXCLUSION ACTIVA: aborta toda peticion de SONDEO, deja pasar el resto.
 *
 * Se cuentan las abortadas: que el contador sea 0 no se interpreta como
 * "el bloqueo funciono", sino que se informa, porque hoy significa que el
 * sondeo ni siquiera lo intenta (DEF-05).
 */
export async function bloquearSondeo(page) {
    const abortadas = [];
    await page.route(LIVEWIRE_UPDATE, async (route) => {
        const req = route.request();
        const metodos = metodosDe(req.postData());
        if (metodos.some((m) => METODOS_SONDEO.includes(m))) {
            abortadas.push({ at: Date.now(), metodos });
            return route.abort();
        }
        return route.fallback();
    });
    return { abortadas, total: () => abortadas.length };
}

/**
 * EXCLUSION ACTIVA, ESPEJO DE `bloquearSondeo`: aborta todo lo que NO sea un
 * sondeo (rebote de `setConnectionState`, puente `onStatsUpdated`, ...), y deja
 * pasar unicamente `refreshStats`.
 *
 * POR QUE HACE FALTA (Fase 6d). Mientras el sondeo estuvo muerto (DEF-05) la
 * unica confusion posible era atribuir AL WEBSOCKET lo que hacia el sondeo.
 * Con el sondeo ya operativo la confusion se vuelve simetrica, y ademas sigue
 * viva la trampa concreta que me colo un verde falso en la Fase 6c: el
 * contador subia de rebote por `setConnectionState`, no por el sondeo.
 *
 * Con este bloqueo, si el contador sube, SOLO ha podido pedirlo `refreshStats`:
 * a ninguna otra llamada se le permitio llegar al servidor.
 */
export async function bloquearRebote(page) {
    const abortadas = [];
    await page.route(LIVEWIRE_UPDATE, async (route) => {
        const req = route.request();
        const metodos = metodosDe(req.postData());
        const esSondeo = metodos.some((m) => METODOS_SONDEO.includes(m));
        if (!esSondeo) {
            abortadas.push({ at: Date.now(), metodos });
            return route.abort();
        }
        return route.fallback();
    });
    return {
        abortadas,
        total: () => abortadas.length,
        resumen: () => abortadas.map((a) => a.metodos.join('|') || '(sin calls)'),
    };
}

/** Estado del dashboard tal y como lo ve el navegador. */
export function leerDom(page) {
    return page.evaluate(() => {
        const el = document.querySelector('[data-dashboard]');
        if (!el) return null;
        return {
            poll: el.getAttributeNames().filter((n) => n.startsWith('wire:poll')),
            estado: el.getAttribute('data-connection-state'),
            intervalo: el.getAttribute('data-poll-interval'),
        };
    });
}

/** Envia una respuesta valida como un participante nuevo e independiente. */
export async function enviarRespuesta(api, survey) {
    const html = await (await api.get(`/s/${survey.public_id}`)).text();
    const token = html.match(/name="_token" value="([^"]+)"/)?.[1];
    const single = survey.questions.find((q) => q.type === 'single_choice');
    const multi = survey.questions.find((q) => q.type === 'multiple_choice');

    const res = await api.post(`/s/${survey.public_id}/enviar`, {
        form: {
            _token: token,
            [`q[${single.id}]`]: String(single.options[0].id),
            [`q[${multi.id}][]`]: String(multi.options[0].id),
        },
        maxRedirects: 0,
        failOnStatusCode: false,
    });
    return res.status();
}

/** ¿Responde el puerto de Reverb? */
export async function reverbVivo() {
    try {
        const res = await fetch('http://127.0.0.1:8080/app/x', { signal: AbortSignal.timeout(2500) });
        return res.status > 0;
    } catch {
        return false;
    }
}

/**
 * Espera a que un localizador cambie de valor, devolviendo la LATENCIA real.
 * Sondea el DOM cada 100 ms; no usa `toHaveText` porque aqui el dato que
 * interesa no es solo que cambie, sino CUANDO.
 */
export async function esperarCambio(page, locator, valorPrevio, timeoutMs) {
    const inicio = Date.now();
    while (Date.now() - inicio < timeoutMs) {
        const v = (await locator.innerText()).trim();
        if (v !== valorPrevio) return { cambio: true, ms: Date.now() - inicio, valor: v };
        await page.waitForTimeout(100);
    }
    return { cambio: false, ms: Date.now() - inicio, valor: valorPrevio };
}
