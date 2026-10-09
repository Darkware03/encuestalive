import { test, expect } from '@playwright/test';
import { writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { loadFixture, adminContext, ROOT } from './support/fixture.mjs';

/*
 * ============================================================================
 * PRIORIDAD 3 — busqueda activa de defectos
 * ============================================================================
 *
 * Estas pruebas no confirman el camino feliz: intentan ROMPER el sistema.
 *
 * El aviso que gobierna medio fichero: SQLITE NO APLICA LONGITUDES DE VARCHAR.
 * Si la validacion de PHP fallara, un texto de 100.000 caracteres entraria sin
 * una queja en desarrollo y reventaria en MySQL 8, que es el destino declarado
 * (D-04). Por eso los limites se prueban POR ENCIMA, no solo por debajo: un
 * limite que solo se prueba con valores validos no esta probado.
 */

const fx = loadFixture();
const active = fx.surveys.active;
const race = fx.surveys.race;
const single = active.questions.find((q) => q.type === 'single_choice');
const multi = active.questions.find((q) => q.type === 'multiple_choice');
const free = active.questions.find((q) => q.type === 'free_text');

const MAX = free.max_length; // 200 en el fixture

const hallazgos = [];
function anotar(caso, enviado, status, nota) {
    hallazgos.push({ caso, enviado, status, nota });
}

async function nuevoParticipante(playwright, publicId = active.public_id) {
    const ctx = await playwright.request.newContext({ baseURL: 'http://127.0.0.1:8000' });
    const html = await (await ctx.get(`/s/${publicId}`)).text();
    const token = html.match(/name="_token" value="([^"]+)"/)?.[1];
    return { ctx, token };
}

async function enviarTexto(playwright, texto) {
    const { ctx, token } = await nuevoParticipante(playwright);
    const res = await ctx.post(`/s/${active.public_id}/responses`, {
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token },
        data: {
            submission_uuid: crypto.randomUUID(),
            answers: [
                { question_id: single.id, option_ids: [single.options[0].id] },
                { question_id: multi.id, option_ids: [multi.options[0].id] },
                { question_id: free.id, text: texto },
            ],
        },
        failOnStatusCode: false,
    });
    const cuerpo = await res.json().catch(() => ({}));
    await ctx.dispose();
    return { status: res.status(), cuerpo };
}

/* ===================================== longitudes en el limite y por encima */

test('Limites · texto exactamente en el limite entra; uno por encima se rechaza', async ({ playwright }) => {
    test.setTimeout(120_000);

    const justo = 'a'.repeat(MAX);
    const r1 = await enviarTexto(playwright, justo);
    anotar(`texto de ${MAX} (limite exacto)`, MAX, r1.status, 'debe aceptarse');
    expect(r1.status, `texto de exactamente ${MAX} caracteres`).toBe(200);

    const pasado = 'a'.repeat(MAX + 1);
    const r2 = await enviarTexto(playwright, pasado);
    anotar(`texto de ${MAX + 1} (uno por encima)`, MAX + 1, r2.status, 'debe rechazarse con 422');
    expect(r2.status, `texto de ${MAX + 1} caracteres`).toBe(422);
    expect(JSON.stringify(r2.cuerpo)).toMatch(/no puede superar/i);
});

test('Limites · un texto absurdo (100.000 caracteres) se rechaza en PHP, no en SQLite', async ({ playwright }) => {
    test.setTimeout(120_000);
    const bestial = 'x'.repeat(100_000);
    const r = await enviarTexto(playwright, bestial);
    anotar('texto de 100000', 100_000, r.status, 'SQLite no aplica VARCHAR: lo tiene que parar PHP');
    expect(r.status, 'un texto de 100k debe rechazarse con 422, nunca guardarse').toBe(422);
});

test('Limites · nada de longitud desbordada llego a la base de datos', async ({ playwright, browser }) => {
    /*
     * La comprobacion que de verdad cierra el asunto: despues de intentar
     * meter textos por encima del limite, se mira la BASE DE DATOS. Si la
     * validacion de PHP tuviera un hueco, aqui apareceria una fila larga,
     * porque SQLite la habria aceptado encantada.
     */
    const ctx = await adminContext(browser, 'admin');
    const page = await ctx.newPage();
    const csv = await (await page.request.get(`/admin/encuestas/${active.id}/export.csv`)).text();

    const campos = [...csv.matchAll(/"([^"]*)"/g)].map((m) => m[1]);
    const largos = campos.filter((c) => c.length > MAX);
    expect(largos.map((l) => l.length), `hay textos por encima de ${MAX} almacenados`).toHaveLength(0);

    await ctx.close();
});

/* =============================================== Unicode, emoji y rarezas */

test('Unicode · emoji, combinaciones y escrituras no latinas se guardan y se pintan bien', async ({ playwright, browser }) => {
    test.setTimeout(120_000);

    const muestras = [
        { nombre: 'emoji con modificador', texto: '👩🏽‍💻 excelente 🎉🇸🇻' },
        { nombre: 'combinantes', texto: 'nin\u0303o cafe\u0301 an\u0303o' },
        { nombre: 'no latino', texto: 'مرحبا שלום 你好 こんにちは' },
        { nombre: 'bidi y control', texto: 'antes\u202Edespues\u202C fin' },
        { nombre: 'cero ancho', texto: 'a\u200Bb\u200Cc\u200Dd' },
    ];

    for (const m of muestras) {
        const r = await enviarTexto(playwright, m.texto);
        anotar(`unicode: ${m.nombre}`, m.texto.length, r.status, 'debe aceptarse sin corromperse');
        expect(r.status, `unicode ${m.nombre}`).toBe(200);
    }

    // Y ahora se comprueba que vuelven ENTEROS, sin mojibake.
    const ctx = await adminContext(browser, 'admin');
    const page = await ctx.newPage();
    const csv = await (await page.request.get(`/admin/encuestas/${active.id}/export.csv`)).text();

    for (const m of muestras) {
        expect(csv, `"${m.nombre}" debe volver intacto del CSV`).toContain(m.texto);
    }
    expect(csv, 'no debe haber caracter de sustitucion (mojibake)').not.toContain('\uFFFD');

    await ctx.close();
});

test('Limites · la longitud se cuenta en CARACTERES, no en bytes', async ({ playwright }) => {
    /*
     * Un emoji ocupa 4 bytes. Si el limite se aplicara con strlen() en lugar
     * de mb_strlen(), 60 emojis (240 bytes) se rechazarian pese a ser solo
     * 60 caracteres, y el participante no entenderia por que.
     */
    const sesenta = '😀'.repeat(60); // 60 caracteres, 240 bytes
    const r = await enviarTexto(playwright, sesenta);
    anotar('60 emoji (240 bytes)', 60, r.status, 'mb_strlen debe contar 60, no 240');
    expect(r.status, '60 emoji estan por debajo del limite de 200 caracteres').toBe(200);
});

/* ============================================ manipulacion del identificador */

test('Identificador publico · manipularlo no abre nada ajeno', async ({ playwright }) => {
    const ctx = await playwright.request.newContext({ baseURL: 'http://127.0.0.1:8000' });

    const intentos = [
        { id: 'E2E0ACTVA0000002', nota: 'un digito cambiado' },
        { id: 'e2e0actva0000001', nota: 'en minusculas' },
        { id: 'E2E0ACTVA000000', nota: '15 caracteres' },
        { id: 'E2E0ACTVA00000011', nota: '17 caracteres' },
        { id: 'E2E0ACTIVA000001', nota: 'con I, fuera del alfabeto Crockford' },
        { id: '../../admin', nota: 'recorrido de rutas' },
        { id: "' OR 1=1 --", nota: 'inyeccion SQL' },
        { id: '%2e%2e%2fadmin', nota: 'recorrido codificado' },
        { id: '<script>alert(1)</script>', nota: 'XSS en la ruta' },
    ];

    const observado = [];
    for (const i of intentos) {
        const res = await ctx.get(`/s/${encodeURIComponent(i.id)}`, { maxRedirects: 0, failOnStatusCode: false });
        observado.push({ ...i, status: res.status() });

        // Nunca 200: ni la encuesta de otro, ni nada administrativo.
        expect(res.status(), `${i.nota} (${i.id}) no debe devolver 200`).not.toBe(200);

        const cuerpo = await res.text();
        expect(cuerpo, 'no debe filtrar el titulo de ninguna encuesta').not.toContain(active.title);
        // Y el reflejo de la entrada no puede volver sin escapar.
        expect(cuerpo).not.toContain('<script>alert(1)</script>');
    }

    writeFileSync(resolve(ROOT, 'storage/app/f6/robustez-publicid.json'), JSON.stringify(observado, null, 2));
    await ctx.dispose();
});

/* =================================== transiciones de estado por HTTP directo */

test('Estados · un participante no puede provocar transiciones de estado', async ({ playwright }) => {
    const { ctx, token } = await nuevoParticipante(playwright);

    // No existe ninguna ruta publica de cambio de estado. Se intenta igual,
    // por si alguna quedase expuesta sin querer.
    for (const ruta of [
        `/s/${active.public_id}/close`,
        `/s/${active.public_id}/status`,
        `/s/${active.public_id}/publish`,
        `/admin/encuestas/${active.id}/cerrar`,
    ]) {
        const res = await ctx.post(ruta, {
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token },
            data: { status: 'closed' },
            maxRedirects: 0,
            failOnStatusCode: false,
        });
        expect([404, 405, 302, 403, 419], `${ruta} no debe ejecutar nada`).toContain(res.status());
    }

    // Y la encuesta sigue activa.
    const estado = await (await ctx.get(`/s/${active.public_id}/state`)).json();
    expect(estado.survey.status).toBe('active');
    expect(estado.survey.accepts_responses).toBe(true);

    await ctx.dispose();
});

/* ================================= carrera: cerrar mientras alguien envia */

test('Carrera · cerrar la encuesta mientras se envia no deja datos a medias', async ({ playwright, browser }) => {
    test.setTimeout(180_000);

    /*
     * El escenario incomodo: una persona pulsa "enviar" en el mismo instante
     * en que la organizadora cierra la encuesta.
     *
     * No se exige un ganador concreto: las dos resoluciones son legitimas
     * (se acepta justo antes de cerrar, o se rechaza con 410 justo despues).
     * Lo que NO es aceptable es un 500, un envio a medias o un contador que
     * no cuadre con las filas. Eso es lo que se comprueba.
     */
    const preparados = [];
    for (let i = 0; i < 6; i += 1) {
        preparados.push(await nuevoParticipante(playwright, race.public_id));
    }

    const q1 = race.questions.find((q) => q.type === 'single_choice');
    const q2 = race.questions.find((q) => q.type === 'multiple_choice');

    const enviar = ({ ctx, token }) => ctx.post(`/s/${race.public_id}/responses`, {
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token },
        data: {
            submission_uuid: crypto.randomUUID(),
            answers: [
                { question_id: q1.id, option_ids: [q1.options[0].id] },
                { question_id: q2.id, option_ids: [q2.options[0].id] },
            ],
        },
        failOnStatusCode: false,
    }).then(async (r) => ({ status: r.status(), body: await r.json().catch(() => ({})) }))
      .catch((e) => ({ status: 0, error: String(e).slice(0, 120) }));

    // El cierre se dispara por el MODELO, en paralelo a los envios.
    const cerrar = (async () => {
        const { execFileSync } = await import('node:child_process');
        return execFileSync('php', ['artisan', 'tinker', '--execute',
            `$s = App\\Models\\Survey::where('public_id','${race.public_id}')->first();
             $s->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
             echo 'cerrada';`], {
            cwd: ROOT,
            env: { ...process.env, DB_DATABASE: resolve(ROOT, 'storage/app/f6/e2e.sqlite') },
            encoding: 'utf8',
        });
    })();

    const [resultados] = await Promise.all([
        Promise.all(preparados.map(enviar)),
        cerrar,
    ]);
    await Promise.all(preparados.map((p) => p.ctx.dispose()));

    const porEstado = resultados.reduce((acc, r) => {
        acc[r.status] = (acc[r.status] ?? 0) + 1;
        return acc;
    }, {});

    /*
     * 1. INTEGRIDAD, que es lo que de verdad no puede fallar.
     *
     * Nota sobre DEF-02: en esta carrera aparecen HTTP 500 por
     * `database is locked` (12,5 % medido con tools/f6/probe-carrera.mjs).
     * Ese fallo se verifica en su propia prueba, la siguiente, marcada como
     * fallo esperado. Aqui se comprueba lo que NO puede romperse ni siquiera
     * con ese 500 de por medio: que no queden datos a medias.
     */
    expect(porEstado['0'] ?? 0, 'ninguna conexion rota').toBe(0);

    // 2. COHERENCIA: las respuestas guardadas = los 200 devueltos. Ni una fila
    //    huerfana de un envio que el cliente creyo rechazado.
    const { execFileSync } = await import('node:child_process');
    const salida = execFileSync('php', ['artisan', 'tinker', '--execute',
        `$s = App\\Models\\Survey::where('public_id','${race.public_id}')->first();
         echo 'filas='.App\\Models\\Response::where('survey_id',$s->id)->count();
         echo ' huerfanas='.App\\Models\\Response::where('survey_id',$s->id)
              ->whereDoesntHave('answers')->count();
         echo ' estado='.$s->status;`], {
        cwd: ROOT,
        env: { ...process.env, DB_DATABASE: resolve(ROOT, 'storage/app/f6/e2e.sqlite') },
        encoding: 'utf8',
    });

    const filas = Number(salida.match(/filas=(\d+)/)?.[1]);
    const huerfanas = Number(salida.match(/huerfanas=(\d+)/)?.[1]);
    const aceptados = porEstado['200'] ?? 0;

    writeFileSync(resolve(ROOT, 'storage/app/f6/carrera-cierre.json'), JSON.stringify({
        porEstado, filas, huerfanas, aceptados, estado_final: salida.match(/estado=(\w+)/)?.[1],
    }, null, 2));

    expect(huerfanas, 'ninguna respuesta sin sus answers (transaccion a medias)').toBe(0);
    expect(filas, 'las filas guardadas deben coincidir con los 200 devueltos').toBe(aceptados);

    // Y los estados observados solo pueden ser aceptado, cerrado o el 500 de
    // DEF-02; cualquier otro codigo seria un defecto NUEVO y sin documentar.
    const inesperados = Object.keys(porEstado).filter((s) => !['200', '410', '500'].includes(s));
    expect(inesperados, `codigos inesperados: ${JSON.stringify(porEstado)}`).toHaveLength(0);

    // Se deja la encuesta de carrera como estaba para poder repetir la prueba.
    execFileSync('php', ['artisan', 'tinker', '--execute',
        `App\\Models\\Survey::where('public_id','${race.public_id}')
          ->update(['status' => 'active', 'closed_at' => null]);`], {
        cwd: ROOT,
        env: { ...process.env, DB_DATABASE: resolve(ROOT, 'storage/app/f6/e2e.sqlite') },
        encoding: 'utf8',
    });
});

test('Carrera · DEFECTO DEF-02: cerrar mientras se envia produce HTTP 500 (database is locked)', async ({ playwright }) => {
    /*
     * DEF-02, medido: 3 de 24 envios (12,5 %) devuelven HTTP 500 cuando el
     * cierre de la encuesta coincide con los envios.
     *
     * Causa: SQLite admite UN SOLO ESCRITOR. El proceso que cierra la encuesta
     * y los procesos que insertan `participant_sessions`/`responses` compiten
     * por el bloqueo de escritura. `busy_timeout` esta correctamente puesto en
     * 5000 ms (verificado: PRAGMA busy_timeout=5000, journal_mode=wal), pero
     * SQLite NO invoca el manejador de ocupado cuando una transaccion
     * diferida tiene que ascender de lectura a escritura y otro escritor ya
     * tomo el bloqueo: devuelve SQLITE_BUSY de inmediato.
     *
     * Lo AGRAVA que CACHE_STORE=database ponga la tabla `cache` en el MISMO
     * fichero: el limitador de tasa escribe en cada peticion, de modo que
     * cada peticion es tambien un escritor compitiendo.
     *
     * Lo que NO ocurre, y por eso la severidad es media y no critica: no hay
     * respuestas a medias, ni filas huerfanas, ni contadores descuadrados.
     * La transaccion de ParticipationService hace su trabajo. El dano es que
     * el participante ve un error del servidor en lugar de un 410 honesto.
     *
     * Riesgo residual para el destino declarado (MySQL 8, D-04): este modo de
     * fallo es propio de SQLite y NO deberia reproducirse en MySQL, que no
     * tiene la limitacion de un unico escritor. No se ha podido comprobar:
     * en este host no hay MySQL.
     *
     * ---------------- POR QUE ESTA PRUEBA *MIDE* Y NO *FALLA* ----------------
     * El defecto es INTERMITENTE (12,5 %). La primera version usaba
     * `test.fail()`, y en la ejecucion siguiente la carrera no se disparo:
     * los 6 envios salieron 410 y Playwright reporto "Expected to fail, but
     * passed", poniendo la bateria en rojo por un defecto que seguia ahi.
     *
     * Codificar un fallo intermitente como fallo esperado produce una bateria
     * que miente en las dos direcciones. Asi que esta prueba repite la carrera
     * varias rondas, DEJA ESCRITA la tasa observada como evidencia, y solo
     * falla si se rompe algo determinista: la integridad de los datos.
     * La existencia de DEF-02 esta acreditada por la medicion, no por el
     * resultado binario de esta ejecucion concreta.
     */
    test.setTimeout(240_000);

    const q1 = race.questions.find((q) => q.type === 'single_choice');
    const q2 = race.questions.find((q) => q.type === 'multiple_choice');
    const { execFileSync } = await import('node:child_process');
    const env = { ...process.env, DB_DATABASE: resolve(ROOT, 'storage/app/f6/e2e.sqlite') };
    const php = (code) => execFileSync('php', ['artisan', 'tinker', '--execute', code],
        { cwd: ROOT, env, encoding: 'utf8' });

    const RONDAS = 4;
    const POR_RONDA = 6;
    const rondas = [];

    for (let r = 0; r < RONDAS; r += 1) {
        php(`App\\Models\\Survey::where('public_id','${race.public_id}')->update(['status'=>'active','closed_at'=>null]);
             $s = App\\Models\\Survey::where('public_id','${race.public_id}')->first();
             App\\Models\\Response::where('survey_id',$s->id)->get()->each->delete();
             App\\Models\\ParticipantSession::where('survey_id',$s->id)->delete();`);

        /*
         * GUARDA DE PREPARACION. Sin esto la prueba se enganaba sola.
         *
         * La ronda anterior deja la encuesta CERRADA. Si los participantes
         * abren la pagina antes de que la reapertura sea visible, no reciben
         * formulario, por tanto no hay `_token`, y los seis POST vuelven 419
         * (token invalido) en lugar de 200/410. Eso ocurrio de verdad: una
         * ronda entera salio 419 y contamino la medicion.
         *
         * 419 no dice nada sobre la carrera: solo dice que la ronda no estaba
         * lista. Se espera a que el servidor confirme `accepts_responses`
         * antes de empezar, y se exige que todos tengan token.
         */
        const sonda = await playwright.request.newContext({ baseURL: 'http://127.0.0.1:8000' });
        for (let intento = 0; intento < 20; intento += 1) {
            const x = await sonda.get(`/s/${race.public_id}/state`, { failOnStatusCode: false });
            if (x.status() === 200 && (await x.json()).survey?.accepts_responses === true) break;
            await new Promise((ok) => setTimeout(ok, 500));
        }
        await sonda.dispose();

        const preparados = [];
        for (let i = 0; i < POR_RONDA; i += 1) {
            preparados.push(await nuevoParticipante(playwright, race.public_id));
        }

        /*
         * RONDA NO PREPARADA = RONDA DESCARTADA, no bateria en rojo.
         *
         * De vez en cuando la reapertura no llega a verse antes de que los
         * participantes abran la pagina (la propia contencion de escritura que
         * se esta midiendo puede retrasar el UPDATE). Sin formulario no hay
         * token, y los seis envios saldrian 419: una ronda que no mide nada.
         *
         * Antes esto tumbaba la prueba entera. Pero una ronda mal preparada no
         * es un defecto del producto NI una medicion valida: es ruido. Se
         * descarta y se deja constancia de cuantas rondas fueron validas, que
         * es lo honesto; inventar un resultado para ella seria peor.
         */
        if (preparados.some((p) => !p.token)) {
            console.log(`[DEF-02] ronda ${r + 1} descartada: la encuesta no estaba reabierta a tiempo`);
            await Promise.all(preparados.map((p) => p.ctx.dispose()));
            continue;
        }

        const enviar = ({ ctx, token }) => ctx.post(`/s/${race.public_id}/responses`, {
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token },
            data: {
                submission_uuid: crypto.randomUUID(),
                answers: [
                    { question_id: q1.id, option_ids: [q1.options[0].id] },
                    { question_id: q2.id, option_ids: [q2.options[0].id] },
                ],
            },
            failOnStatusCode: false,
        }).then((x) => x.status()).catch(() => 0);

        const [estados] = await Promise.all([
            Promise.all(preparados.map(enviar)),
            (async () => php(`App\\Models\\Survey::where('public_id','${race.public_id}')
                 ->update(['status'=>'closed','closed_at'=>now()]);`))(),
        ]);
        await Promise.all(preparados.map((p) => p.ctx.dispose()));

        const salida = php(`$s = App\\Models\\Survey::where('public_id','${race.public_id}')->first();
            echo 'filas='.App\\Models\\Response::where('survey_id',$s->id)->count();
            echo ' huerfanas='.App\\Models\\Response::where('survey_id',$s->id)->whereDoesntHave('answers')->count();`);

        rondas.push({
            ronda: r + 1,
            estados,
            aceptados: estados.filter((s) => s === 200).length,
            cerrados: estados.filter((s) => s === 410).length,
            quinientos: estados.filter((s) => s === 500).length,
            filas: Number(salida.match(/filas=(\d+)/)?.[1]),
            huerfanas: Number(salida.match(/huerfanas=(\d+)/)?.[1]),
        });
    }

    php(`App\\Models\\Survey::where('public_id','${race.public_id}')->update(['status'=>'active','closed_at'=>null]);`);

    // Solo cuentan las rondas que de verdad se ejecutaron.
    expect(rondas.length, 'ninguna ronda valida: la prueba no midio nada').toBeGreaterThan(0);

    const total = rondas.length * POR_RONDA;
    const quinientos = rondas.reduce((a, x) => a + x.quinientos, 0);
    const tasa = (quinientos / total) * 100;

    writeFileSync(resolve(ROOT, 'storage/app/f6/def02-carrera.json'), JSON.stringify({
        defecto: 'DEF-02 · database is locked al cerrar mientras se envia',
        rondas_validas: `${rondas.length} de ${RONDAS}`,
        envios: total,
        http_500: quinientos,
        tasa_pct: Number(tasa.toFixed(1)),
        integridad: { huerfanas: rondas.reduce((a, x) => a + x.huerfanas, 0) },
        rondas,
    }, null, 2));

    test.info().annotations.push({
        type: 'DEF-02',
        description: `HTTP 500 por \`database is locked\`: ${quinientos}/${total} envios (${tasa.toFixed(1)} %)`,
    });

    // DETERMINISTA: pase lo que pase con la carrera, la integridad aguanta.
    for (const x of rondas) {
        expect(x.huerfanas, `ronda ${x.ronda}: respuestas sin answers`).toBe(0);
        expect(x.filas, `ronda ${x.ronda}: filas != 200 devueltos`).toBe(x.aceptados);
        const raros = x.estados.filter((s) => ![200, 410, 500].includes(s));
        expect(raros, `ronda ${x.ronda}: codigos inesperados ${JSON.stringify(x.estados)}`).toHaveLength(0);
    }

    console.log(`\n[DEF-02] HTTP 500 por bloqueo de SQLite: ${quinientos}/${total} (${tasa.toFixed(1)} %), huerfanas=0`);
});

test.afterAll(async () => {
    writeFileSync(resolve(ROOT, 'storage/app/f6/robustez-hallazgos.json'), JSON.stringify(hallazgos, null, 2));
});
