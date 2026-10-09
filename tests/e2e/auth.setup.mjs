import { test as setup, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';
import { loadFixture, ROOT, AUTH_STATE } from './support/fixture.mjs';

/*
 * ============================================================================
 * Autenticacion UNA SOLA VEZ para toda la bateria.
 * ============================================================================
 *
 * POR QUE EXISTE ESTE FICHERO, que es una leccion aprendida a golpes:
 *
 * La primera version de los E2E hacia login por la interfaz en CADA prueba.
 * Funciono hasta que dejo de funcionar: `RateLimiter::for('login')` aplica
 * 5 intentos por minuto Y 20 POR HORA, con clave email+IP
 * (RateLimitServiceProvider.php:57-65). Tras una tarde de ejecuciones
 * repetidas se agoto la cuota horaria y los login empezaron a expirar.
 *
 * Eso NO es un defecto del producto: es exactamente el control que el brief
 * pide contra la fuerza bruta, funcionando. El defecto era de la bateria, que
 * gastaba un recurso limitado sin necesidad.
 *
 * Solucion: iniciar sesion una vez por rol, guardar el estado de sesion y
 * reutilizarlo. Ademas de no chocar con el limitador, hace la bateria mas
 * rapida y re-ejecutable.
 *
 * Se limpia tambien la cache (donde vive el contador del limitador) para que
 * una ejecucion anterior no deje la cuota agotada a la siguiente.
 */

const fx = loadFixture();

function artisan(args) {
    return execFileSync('php', ['artisan', ...args], {
        cwd: ROOT,
        env: {
            ...process.env,
            DB_DATABASE: resolve(ROOT, 'storage/app/f6/e2e.sqlite'),
            BROADCAST_CONNECTION: 'reverb',
        },
        encoding: 'utf8',
    });
}

setup('limpia el limitador y guarda la sesion de los dos administradores', async ({ browser }) => {
    setup.setTimeout(120_000);

    // El contador del limitador vive en la cache (CACHE_STORE=database).
    try {
        artisan(['cache:clear']);
    } catch (e) {
        console.warn('[auth.setup] no se pudo limpiar la cache:', String(e).slice(0, 200));
    }

    for (const [role, creds] of [['admin', fx.admin], ['other', fx.other_admin]]) {
        const ctx = await browser.newContext();
        const page = await ctx.newPage();

        await page.goto('/login');
        await page.fill('input[name=email]', creds.email);
        await page.fill('input[name=password]', creds.password);
        await Promise.all([
            page.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 30_000 }),
            page.click('button[type=submit]'),
        ]);

        // Se confirma que la sesion es real antes de guardarla: guardar un
        // estado no autenticado haria fallar TODAS las pruebas con un error
        // que no senala a la causa.
        const probe = await page.request.get('/admin/encuestas', { failOnStatusCode: false });
        expect(probe.status(), `sesion de ${role} valida`).toBe(200);

        await ctx.storageState({ path: AUTH_STATE[role] });
        await ctx.close();
    }
});
