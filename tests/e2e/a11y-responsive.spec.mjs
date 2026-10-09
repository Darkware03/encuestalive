import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { loadFixture, adminContext, SHOTS, ROOT } from './support/fixture.mjs';

/*
 * ============================================================================
 * Accesibilidad automatizable y diseno adaptable · CA-18 (capturas reales)
 * ============================================================================
 *
 * MOTOR DE REGLAS DECLARADO: axe-core 4.14.0, via @axe-core/playwright 4.13.0.
 * Etiquetas analizadas: wcag2a, wcag2aa, wcag21a, wcag21aa.
 *
 * LO QUE UN MOTOR AUTOMATICO NO PUEDE DECIDIR, y por tanto NO se declara
 * aprobado aqui: si el texto alternativo es adecuado, si el orden de lectura
 * tiene sentido, si un mensaje de error es comprensible, o si la navegacion
 * por teclado es razonable para una persona real. axe detecta del orden del
 * 30-40 % de los problemas reales de accesibilidad. Lo que sigue es un
 * cribado, no un certificado de conformidad con WCAG.
 *
 * Por eso ademas del motor se comprueban a mano cuatro cosas concretas y
 * medibles: orden de foco, etiquetas asociadas, objetivos tactiles de 44 px y
 * la region `aria-live` del dashboard.
 */

const fx = loadFixture();
const active = fx.surveys.active;

const ANCHOS = [
    { nombre: '320-movil-pequeno', width: 320, height: 720 },
    { nombre: '390-movil', width: 390, height: 844 },
    { nombre: '768-tableta', width: 768, height: 1024 },
    { nombre: '1280-escritorio', width: 1280, height: 900 },
];

const WCAG = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];
const hallazgos = [];

async function analizar(page, pantalla, ancho) {
    const r = await new AxeBuilder({ page }).withTags(WCAG).analyze();
    hallazgos.push({
        pantalla,
        ancho,
        motor: 'axe-core 4.14.0',
        etiquetas: WCAG,
        violaciones: r.violations.map((v) => ({
            id: v.id,
            impacto: v.impact,
            descripcion: v.description,
            nodos: v.nodes.length,
            ejemplo: v.nodes[0]?.html?.slice(0, 160),
        })),
        aprobadas: r.passes.length,
    });
    return r.violations;
}

/* =========================================== participacion en 4 anchuras */

test('Responsive y axe · pantalla publica de participacion en 320/390/768/1280', async ({ browser }) => {
    test.setTimeout(180_000);

    for (const v of ANCHOS) {
        const ctx = await browser.newContext({ viewport: { width: v.width, height: v.height } });
        const page = await ctx.newPage();
        await page.goto(`/s/${active.public_id}`);
        await expect(page.getByRole('heading', { name: active.title })).toBeVisible();

        await page.screenshot({ path: resolve(SHOTS, `responsive-participante-${v.nombre}.png`), fullPage: true });

        /* Sin desbordamiento horizontal: en 320 px es donde todo se rompe. */
        const overflow = await page.evaluate(() =>
            document.documentElement.scrollWidth - document.documentElement.clientWidth);
        expect(overflow, `desbordamiento horizontal a ${v.width}px`).toBeLessThanOrEqual(1);

        const violaciones = await analizar(page, 'participacion', v.width);
        const graves = violaciones.filter((x) => ['critical', 'serious'].includes(x.impact));
        expect(graves, `axe criticas/graves a ${v.width}px: ${graves.map((g) => g.id).join(', ')}`).toHaveLength(0);

        await ctx.close();
    }
});

/* ====================================================== objetivos tactiles */

test('A11y · los objetivos tactiles del formulario miden al menos 44 px', async ({ browser }) => {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const page = await ctx.newPage();
    await page.goto(`/s/${active.public_id}`);

    /*
     * WCAG 2.5.8 (AA en 2.2) pide 24x24 CSS px; UX_UI_SPEC §10 es mas
     * exigente y fija 44 px para esta pantalla, que se usa de pie y con una
     * mano. Se comprueba contra el criterio del producto, no contra el minimo
     * legal: el producto se comprometio a 44.
     *
     * Se mide la ETIQUETA envolvente y no el <input>, porque es la que recibe
     * la pulsacion: en este diseno el input real esta visualmente oculto.
     */
    const pequenos = await page.evaluate(() => {
        const malos = [];
        document.querySelectorAll('input[type=radio], input[type=checkbox]').forEach((input) => {
            const objetivo = input.closest('label') ?? input;
            const r = objetivo.getBoundingClientRect();
            if (r.height < 44 || r.width < 44) {
                malos.push({ id: input.id, w: Math.round(r.width), h: Math.round(r.height) });
            }
        });
        const boton = document.querySelector('[data-submit-button]');
        if (boton) {
            const r = boton.getBoundingClientRect();
            if (r.height < 44) malos.push({ id: 'submit', w: Math.round(r.width), h: Math.round(r.height) });
        }
        return malos;
    });

    expect(pequenos, `objetivos tactiles por debajo de 44px: ${JSON.stringify(pequenos)}`).toHaveLength(0);
    await ctx.close();
});

/* ============================================= etiquetas y orden de foco */

test('A11y · todo control tiene etiqueta asociada y el orden de foco sigue al visual', async ({ browser }) => {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const page = await ctx.newPage();
    await page.goto(`/s/${active.public_id}`);

    /* 1. Ningun control sin nombre accesible. */
    const sinEtiqueta = await page.evaluate(() => {
        const malos = [];
        document.querySelectorAll('input:not([type=hidden]), textarea, select').forEach((el) => {
            const porFor = el.id && document.querySelector(`label[for="${CSS.escape(el.id)}"]`);
            const envolvente = el.closest('label');
            const aria = el.getAttribute('aria-label') || el.getAttribute('aria-labelledby');
            if (!porFor && !envolvente && !aria) malos.push(el.outerHTML.slice(0, 120));
        });
        return malos;
    });
    expect(sinEtiqueta, `controles sin etiqueta: ${sinEtiqueta.join(' | ')}`).toHaveLength(0);

    /* 2. Cada grupo de opciones va en un fieldset con su legend. */
    const gruposSinLeyenda = await page.evaluate(() =>
        [...document.querySelectorAll('fieldset')].filter((f) => !f.querySelector('legend')).length);
    expect(gruposSinLeyenda).toBe(0);

    /* 3. El orden de tabulacion recorre el documento hacia abajo, sin saltos
     *    hacia atras. Un orden de foco que sube y baja desorienta a quien
     *    navega con teclado aunque el DOM sea valido. */
    const posiciones = [];
    const vistos = new Set();
    for (let i = 0; i < 25; i += 1) {
        await page.keyboard.press('Tab');
        const actual = await page.evaluate(() => {
            const el = document.activeElement;
            if (!el || el === document.body) return null;
            const r = el.getBoundingClientRect();
            return {
                y: Math.round(r.top + window.scrollY),
                etiqueta: el.tagName,
                clave: `${el.tagName}#${el.id}.${el.className}`.slice(0, 120),
            };
        });
        if (!actual) continue;

        /*
         * PARAR AL DAR LA VUELTA.
         *
         * Al llegar al ultimo control, el navegador devuelve el foco al
         * principio del documento (al enlace de salto, arriba del todo). Mi
         * primera version tabulaba 12 veces a ciegas y contaba ese salto como
         * "el foco retrocede", acusando al producto de un defecto que era el
         * comportamiento normal del navegador.
         *
         * Se analiza solo la PRIMERA vuelta: se corta en cuanto un elemento
         * se repite.
         */
        if (vistos.has(actual.clave)) break;
        vistos.add(actual.clave);
        posiciones.push(actual);
    }

    expect(posiciones.length, 'debe haber varios elementos enfocables').toBeGreaterThan(3);

    const retrocesos = posiciones.filter((p, i) => i > 0 && p.y < posiciones[i - 1].y - 80);
    expect(retrocesos, `el foco retrocede: ${JSON.stringify(retrocesos)}`).toHaveLength(0);

    /* 4. Existe un enlace para saltar al contenido. */
    expect(await page.locator('a[href^="#"]').first().isVisible({ timeout: 2000 }).catch(() => true)).toBeTruthy();

    await ctx.close();
});

/* ===================================================== dashboard y aria-live */

test('Responsive y axe · dashboard de resultados, con region aria-live', async ({ browser }) => {
    test.setTimeout(180_000);

    for (const v of ANCHOS) {
        const ctx = await adminContext(browser, 'admin', { viewport: { width: v.width, height: v.height } });
        const page = await ctx.newPage();
        await page.goto(`/admin/encuestas/${active.id}/resultados`);
        await expect(page.locator('[data-dashboard]')).toBeVisible();
        await page.waitForTimeout(1500);

        await page.screenshot({ path: resolve(SHOTS, `responsive-dashboard-${v.nombre}.png`), fullPage: true });

        const overflow = await page.evaluate(() =>
            document.documentElement.scrollWidth - document.documentElement.clientWidth);
        expect(overflow, `desbordamiento horizontal a ${v.width}px`).toBeLessThanOrEqual(1);

        const violaciones = await analizar(page, 'dashboard', v.width);
        const graves = violaciones.filter((x) => ['critical', 'serious'].includes(x.impact));
        expect(graves, `axe criticas/graves a ${v.width}px: ${graves.map((g) => g.id).join(', ')}`).toHaveLength(0);

        if (v.width === 1280) {
            /*
             * El indicador de conexion debe vivir en una region anunciada, y
             * SEPARADA de los datos (UX_UI_SPEC §9.4 regla 3): un cambio de
             * conexion no debe mezclarse con una actualizacion de resultados.
             */
            const vivos = await page.evaluate(() =>
                [...document.querySelectorAll('[aria-live], [role=status], [role=alert]')]
                    .map((el) => ({
                        rol: el.getAttribute('role'),
                        live: el.getAttribute('aria-live'),
                        texto: el.innerText.replace(/\s+/g, ' ').trim().slice(0, 60),
                    })));
            expect(vivos.length, 'el dashboard debe tener alguna region anunciada').toBeGreaterThan(0);
            hallazgos.push({ pantalla: 'dashboard', ancho: v.width, regiones_live: vivos });
        }

        await ctx.close();
    }

    writeFileSync(resolve(ROOT, 'storage/app/f6/a11y-axe.json'), JSON.stringify(hallazgos, null, 2));
});

/* ============================================================= CA-18 · login */

test('CA-18 · capturas reales de las pantallas principales', async ({ browser }) => {
    test.setTimeout(120_000);

    // Login (anonimo)
    const anon = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const lp = await anon.newPage();
    await lp.goto('/login');
    await lp.screenshot({ path: resolve(SHOTS, 'pantalla-01-login.png'), fullPage: true });
    const vLogin = await analizar(lp, 'login', 1280);
    expect(vLogin.filter((x) => ['critical', 'serious'].includes(x.impact))).toHaveLength(0);
    await anon.close();

    // Listado de encuestas y editor (admin)
    const ctx = await adminContext(browser, 'admin', { viewport: { width: 1280, height: 900 } });
    const page = await ctx.newPage();

    await page.goto('/admin/encuestas');
    await page.screenshot({ path: resolve(SHOTS, 'pantalla-02-listado-encuestas.png'), fullPage: true });

    await page.goto(`/admin/encuestas/${active.id}/editar`);
    await page.waitForTimeout(1000);
    await page.screenshot({ path: resolve(SHOTS, 'pantalla-03-editor-encuesta.png'), fullPage: true });

    await page.goto(`/admin/encuestas/${active.id}/resultados`);
    await page.waitForTimeout(1500);
    await page.screenshot({ path: resolve(SHOTS, 'pantalla-04-dashboard-resultados.png'), fullPage: true });

    await page.goto('/privacidad');
    await page.screenshot({ path: resolve(SHOTS, 'pantalla-05-privacidad.png'), fullPage: true });

    await ctx.close();

    writeFileSync(resolve(ROOT, 'storage/app/f6/a11y-axe.json'), JSON.stringify(hallazgos, null, 2));
});
