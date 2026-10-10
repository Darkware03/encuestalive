import { defineConfig, devices } from '@playwright/test';

/*
 * playwright.config.mjs — pruebas E2E de la Fase 6 (QA).
 *
 * D-08 cerro el bloqueo de entorno: Chromium headless arranca desde un sysroot
 * de usuario y Playwright 1.64 esta instalado, asi que los E2E son EXIGIBLES y
 * no se pueden declarar "bloqueados por entorno".
 *
 * ================== LOS SERVICIOS NO LOS LEVANTA PLAYWRIGHT ==================
 * No se usa `webServer`. Hacen falta DOS procesos (aplicacion y Reverb) y,
 * sobre todo, CA-15 consiste precisamente en PARARLOS Y VOLVERLOS A ARRANCAR
 * en mitad de la bateria. Si Playwright fuese el dueno del ciclo de vida, esa
 * prueba no se podria escribir.
 *
 * Se arrancan con `tools/f6/services.sh start` antes de ejecutar.
 * =============================================================================
 *
 * `--no-sandbox --disable-dev-shm-usage` son obligatorios en este contenedor
 * (sin root, sin /dev/shm grande). Los avisos de D-Bus en la salida son ruido
 * conocido y no fatal.
 *
 * workers: 1. Las pruebas comparten una base de datos SQLite y cuentan filas;
 * ejecutarlas en paralelo haria que midieran el ruido de las demas. La
 * concurrencia que SI se prueba se genera dentro de una prueba, a proposito,
 * con varios contextos de navegador simultaneos.
 */

const BASE_URL = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8000';

export default defineConfig({
    testDir: './tests/e2e',
    testMatch: '**/*.spec.mjs',
    timeout: 90_000,
    expect: { timeout: 15_000 },
    fullyParallel: false,
    workers: 1,
    forbidOnly: true,
    retries: 0,
    /*
     * ============ EXCLUSION DE LAS PRUEBAS ETIQUETADAS `@caida` ============
     * Fase 6c. `ca08-caida.spec.mjs` MATA Y REARRANCA Reverb en mitad de la
     * ejecucion para reproducir el escenario real de un evento con mala red.
     * Con workers:1 y servicios compartidos, dejarla en la bateria por
     * defecto haria que las pruebas vecinas fallasen por un motivo ajeno a lo
     * que miden, que es justo la clase de ruido que invalida un informe.
     *
     * Se ejecuta a proposito y por separado:
     *   E2E_INCLUIR_CAIDA=1 npx playwright test tests/e2e/ca08-caida.spec.mjs
     *
     * Se excluye por ETIQUETA y no con `testIgnore` precisamente para que siga
     * siendo ejecutable cuando se pide: un fichero ignorado no se puede correr
     * ni queriendo, y una prueba que no se puede correr acaba pudriendose.
     * =======================================================================
     */
    grepInvert: process.env.E2E_INCLUIR_CAIDA ? undefined : /@caida/,
    reporter: [
        ['list'],
        ['json', { outputFile: 'storage/app/f6/playwright-report.json' }],
    ],
    outputDir: 'storage/app/f6/test-results',
    use: {
        baseURL: BASE_URL,
        actionTimeout: 15_000,
        navigationTimeout: 30_000,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'off',
        ignoreHTTPSErrors: true,
    },
    projects: [
        /*
         * `setup` inicia sesion UNA vez por rol y guarda el estado. El resto
         * de proyectos dependen de el. Motivo: el limitador de /login permite
         * 5 intentos/minuto y 20/hora por email+IP, y hacer login en cada
         * prueba agotaba la cuota y rompia la bateria por una causa ajena a
         * lo que se estaba probando.
         */
        {
            name: 'setup',
            testMatch: /auth\.setup\.mjs/,
            use: {
                ...devices['Desktop Chrome'],
                launchOptions: { args: ['--no-sandbox', '--disable-dev-shm-usage'] },
            },
        },
        {
            name: 'chromium-desktop',
            dependencies: ['setup'],
            use: {
                ...devices['Desktop Chrome'],
                viewport: { width: 1280, height: 900 },
                launchOptions: {
                    args: ['--no-sandbox', '--disable-dev-shm-usage'],
                },
            },
        },
    ],
});
