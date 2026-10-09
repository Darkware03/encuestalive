import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

/*
 * Utilidades compartidas por los E2E.
 *
 * El fixture lo genera `tools/f6/provision.php` y se lee de
 * `storage/app/f6/fixture.json`. Las pruebas NO inventan identificadores ni
 * los escriben a mano: si el aprovisionamiento cambia, las pruebas siguen
 * apuntando al dato correcto en lugar de fallar por una constante obsoleta.
 */

const here = dirname(fileURLToPath(import.meta.url));
export const ROOT = resolve(here, '../../..');

export function loadFixture() {
    return JSON.parse(readFileSync(resolve(ROOT, 'storage/app/f6/fixture.json'), 'utf8'));
}

export const SHOTS = resolve(ROOT, 'storage/app/screenshots/f6');

/** Estados de sesion que guarda `auth.setup.mjs`, uno por rol. */
export const AUTH_STATE = {
    admin: resolve(ROOT, 'storage/app/f6/auth-admin.json'),
    other: resolve(ROOT, 'storage/app/f6/auth-other.json'),
};

/*
 * Contexto ya autenticado, a partir del estado guardado por auth.setup.mjs.
 *
 * NO vuelve a pasar por /login: el limitador de acceso permite 5 intentos por
 * minuto y 20 por hora por email+IP, y una bateria que hace login en cada
 * prueba agota esa cuota y empieza a fallar por una razon que no tiene nada
 * que ver con lo que esta probando.
 *
 * El inicio de sesion por la interfaz real SI se ejerce, una vez, en
 * auth.setup.mjs, y la autenticacion en si la cubre tests/Feature/Auth.
 */
export async function adminContext(browser, role = 'admin', options = {}) {
    return browser.newContext({ storageState: AUTH_STATE[role], ...options });
}

/**
 * Lee el valor de una tarjeta de KPI del dashboard por su etiqueta.
 * La tarjeta es un <article> con <h3> etiqueta y <p> cifra (x-stat).
 */
export function statValue(page, label) {
    return page
        .locator('article')
        .filter({ has: page.getByRole('heading', { level: 3, name: label }) })
        .locator('p')
        .first();
}

/** Rellena el formulario publico con una respuesta valida completa. */
export async function fillValidAnswers(page, survey, { text = null } = {}) {
    const single = survey.questions.find((q) => q.type === 'single_choice');
    const multi = survey.questions.find((q) => q.type === 'multiple_choice');
    const free = survey.questions.find((q) => q.type === 'free_text');

    await page.locator(`#o-${single.options[0].id}`).check();
    await page.locator(`#o-${multi.options[0].id}`).check();

    if (free && text !== null) {
        await page.locator(`#q-${free.id}`).fill(text);
    }
}

export async function submitSurvey(page) {
    await page.locator('[data-submit-button]').click();
}
