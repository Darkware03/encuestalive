import { test, expect } from '@playwright/test';
import { writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { loadFixture, adminContext, SHOTS, ROOT } from './support/fixture.mjs';

/*
 * ============================================================================
 * CA-11 · CA-12 · CA-14 — autorizacion y exportacion
 * ============================================================================
 *
 * CA-12 tiene DOS mitades y las dos se prueban:
 *   a) un ANONIMO no alcanza nada administrativo ni canales privados;
 *   b) un ADMIN AUTENTICADO no alcanza la encuesta de OTRO admin.
 * La segunda es la que de verdad se olvida: "es admin" no es autorizacion.
 *
 * CA-11 no se comprueba leyendo la cabecera del CSV y dando por hecho el
 * resto. Se recorre el fichero ENTERO buscando los valores reales que no
 * pueden aparecer (el hash del token de la sesion, su id, la IP de
 * documentacion sembrada) ademas de los nombres de columna prohibidos.
 */

const fx = loadFixture();
const active = fx.surveys.active;
const alien = fx.surveys.alien;

/* ============================================ CA-12.a · anonimo bloqueado */

test('CA-12 · un anonimo no alcanza ninguna ruta administrativa', async ({ browser }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();

    const rutas = [
        '/admin',
        '/admin/encuestas',
        '/admin/historial',
        `/admin/encuestas/${active.id}/editar`,
        `/admin/encuestas/${active.id}/resultados`,
        `/admin/encuestas/${active.id}/export.csv`,
        `/admin/encuestas/${active.id}/qr.png`,
    ];

    const resultados = [];
    for (const ruta of rutas) {
        const res = await page.request.get(ruta, { maxRedirects: 0, failOnStatusCode: false });
        const location = res.headers()['location'] ?? '';
        resultados.push({ ruta, status: res.status(), location });

        expect(res.status(), `anonimo en ${ruta}`).toBe(302);
        expect(location, `${ruta} debe redirigir a /login`).toContain('/login');

        // Y sobre todo: NUNCA datos parciales en el cuerpo (RF-59).
        const body = await res.text();
        expect(body).not.toContain(active.title);
        expect(body).not.toMatch(/respuesta_n|sesiones conectadas/i);
    }

    // El endpoint de autorizacion de canales tambien.
    const auth = await page.request.post('/broadcasting/auth', {
        form: { socket_id: '1.1', channel_name: `private-survey.${active.public_id}.admin` },
        maxRedirects: 0,
        failOnStatusCode: false,
    });
    expect([302, 401, 403]).toContain(auth.status());
    resultados.push({ ruta: '/broadcasting/auth', status: auth.status(), location: auth.headers()['location'] ?? '' });

    writeFileSync(resolve(ROOT, 'storage/app/f6/ca12-anonimo.json'), JSON.stringify(resultados, null, 2));
    await ctx.close();
});

/* ======================================= CA-12.b · admin ajeno bloqueado */

test('CA-12 · un admin autenticado NO accede a la encuesta de otro admin', async ({ browser }) => {
    const ctx = await adminContext(browser, 'admin'); // dueno de `active`, NO de `alien`
    const page = await ctx.newPage();

    for (const ruta of [
        `/admin/encuestas/${alien.id}/resultados`,
        `/admin/encuestas/${alien.id}/editar`,
        `/admin/encuestas/${alien.id}/export.csv`,
        `/admin/encuestas/${alien.id}/qr.png`,
    ]) {
        const res = await page.request.get(ruta, { failOnStatusCode: false });
        expect(res.status(), `admin ajeno en ${ruta}`).toBe(403);
        const body = await res.text();
        expect(body, 'no debe filtrarse ni el titulo de la encuesta ajena').not.toContain(alien.title);
    }

    await ctx.close();
});

/* =================================================== CA-14 y CA-11 · CSV */

test('CA-14 · el admin descarga el CSV y su contenido se puede comprobar', async ({ browser }) => {
    const ctx = await adminContext(browser, 'admin');
    const page = await ctx.newPage();

    const res = await page.request.get(`/admin/encuestas/${active.id}/export.csv`);
    expect(res.status()).toBe(200);

    const disposition = res.headers()['content-disposition'] ?? '';
    expect(disposition).toContain('attachment');
    expect(disposition).toContain(active.public_id);

    const csv = await res.text();
    writeFileSync(resolve(SHOTS, 'ca14-export.csv'), csv);

    // BOM UTF-8 para que Excel no destroce las tildes.
    expect(csv.charCodeAt(0)).toBe(0xfeff);

    /*
     * Las dos secciones documentadas, con sus cabeceras exactas.
     * TODOS los campos van entrecomillados, cabeceras incluidas: el exportador
     * cita siempre. Mi primera version comparaba la cabecera sin comillas y
     * fallaba, que es el recordatorio de por que la asercion se escribe contra
     * el fichero real y no contra la constante PHP que uno recuerda.
     */
    expect(csv).toContain('"respuesta_n","enviada_en","pregunta_orden","pregunta","tipo","valor"');
    expect(csv).toContain('"pct_sobre_participantes"');
    expect(csv).toContain('"pct_sobre_selecciones"'); // D-06: AMBAS bases

    // El contenido se corresponde con la encuesta: las preguntas reales estan.
    for (const q of active.questions) {
        expect(csv, `la pregunta "${q.prompt}" debe figurar`).toContain(q.prompt);
    }

    // Hay al menos una fila de datos ademas de las cabeceras.
    const filas = csv.split('\r\n').filter((l) => /^"\d+",/.test(l));
    expect(filas.length, 'el CSV debe traer filas de respuesta').toBeGreaterThan(0);

    await ctx.close();
});

test('CA-11 · el CSV no contiene IP ni identificadores tecnicos de seguridad', async ({ browser, playwright }) => {
    const ctx = await adminContext(browser, 'admin');
    const page = await ctx.newPage();

    const csv = await (await page.request.get(`/admin/encuestas/${active.id}/export.csv`)).text();
    const minuscula = csv.toLowerCase();

    /* 1. Ningun nombre de columna ni etiqueta prohibida. */
    for (const prohibido of [
        'ip_address', 'ip address', 'user_agent', 'user agent', 'token_hash',
        'participant_session_id', 'session_id', 'correlation_id', 'security_event',
        'token', 'hash',
    ]) {
        expect(minuscula, `el CSV no debe mencionar "${prohibido}"`).not.toContain(prohibido);
    }

    /* 2. Ninguna cosa con forma de IPv4. El rango de documentacion
     *    192.0.2.0/24 esta sembrado en security_events a proposito, asi que si
     *    hubiera una fuga desde esa tabla, apareceria aqui. */
    const ipv4 = csv.match(/\b(?:\d{1,3}\.){3}\d{1,3}\b/g) ?? [];
    expect(ipv4, `no debe haber direcciones IP: ${ipv4.join(', ')}`).toHaveLength(0);

    /*
     * 3. Ninguna cadena larga con pinta de hash o token hexadecimal.
     *
     * CUIDADO CON EL FALSO POSITIVO, que me mordio: `[0-9a-f]{32,}` tambien
     * encaja con 200 letras 'a' seguidas, porque 'a' es un digito
     * hexadecimal. Las pruebas de limites de robustez.spec.mjs guardan
     * justamente textos como 'aaaa...', asi que mi propio dato de prueba
     * hacia saltar mi propia heuristica y acusaba al exportador de filtrar
     * hashes que no existian.
     *
     * Un hash real tiene ENTROPIA: se exige variedad de caracteres (>= 6
     * distintos) y al menos un digito. Asi 'aaaa...' deja de contar y un
     * sha256 de verdad seguiria detectandose.
     */
    const candidatos = csv.match(/\b[0-9a-f]{32,}\b/gi) ?? [];
    const hashes = candidatos.filter((c) => {
        const distintos = new Set(c.toLowerCase()).size;
        return distintos >= 6 && /\d/.test(c) && /[a-f]/i.test(c);
    });
    expect(hashes, `no debe haber hashes: ${hashes.slice(0, 3).join(', ')}`).toHaveLength(0);

    /* 4. Ni el correo del administrador ni datos de cuenta. */
    expect(minuscula).not.toContain(fx.admin.email.toLowerCase());

    /* 5. `respuesta_n` es un ordinal LOCAL: debe empezar en 1 y ser correlativo,
     *    no un id interno de base de datos. */
    const ordinales = [...csv.matchAll(/^"(\d+)",/gm)].map((m) => Number(m[1]));
    const unicos = [...new Set(ordinales)].sort((a, b) => a - b);
    expect(unicos[0], 'respuesta_n debe empezar en 1, no en un id interno').toBe(1);
    expect(unicos[unicos.length - 1], 'respuesta_n debe ser correlativo').toBe(unicos.length);

    await ctx.close();
});

test('CA-11 · la exportacion de otra encuesta no mezcla datos entre encuestas', async ({ browser }) => {
    const ctx = await adminContext(browser, 'other'); // dueno de `alien`
    const page = await ctx.newPage();

    const csv = await (await page.request.get(`/admin/encuestas/${alien.id}/export.csv`)).text();

    // El texto libre sembrado en la encuesta activa NO puede aparecer aqui.
    expect(csv).not.toContain('Evento real de Reverb');
    expect(csv).not.toContain('Todo correcto, gracias.');
    await ctx.close();
});
