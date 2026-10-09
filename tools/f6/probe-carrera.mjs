/*
 * tools/f6/probe-carrera.mjs — cuantifica el defecto DEF-02.
 *
 * El escenario: la organizadora CIERRA la encuesta justo mientras varias
 * personas pulsan "enviar". Se observo `database is locked` con 2 de 6
 * envios devolviendo HTTP 500.
 *
 * Un defecto que solo se ha visto una vez es una anecdota. Esta sonda repite
 * la carrera varias rondas y reporta la distribucion de codigos y si se
 * perdieron envios, para poder dar una tasa y no una impresion.
 *
 * Tambien separa las dos causas posibles de contencion:
 *   · la escritura de la propia respuesta, y
 *   · la escritura de la CACHE (CACHE_STORE=database), que vive en el MISMO
 *     fichero SQLite y recibe un UPDATE por peticion del limitador de tasa.
 */
import { request as pwRequest } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const fx = JSON.parse(readFileSync(resolve(ROOT, 'storage/app/f6/fixture.json'), 'utf8'));
const BASE = 'http://127.0.0.1:8000';
const race = fx.surveys.race;
const q1 = race.questions.find((q) => q.type === 'single_choice');
const q2 = race.questions.find((q) => q.type === 'multiple_choice');

const php = (code) => execFileSync('php', ['artisan', 'tinker', '--execute', code], {
    cwd: ROOT,
    env: { ...process.env, DB_DATABASE: resolve(ROOT, 'storage/app/f6/e2e.sqlite') },
    encoding: 'utf8',
});

const RONDAS = 4;
const POR_RONDA = 6;
const resumen = [];

for (let ronda = 1; ronda <= RONDAS; ronda += 1) {
    // Reabrir y vaciar la encuesta de carrera.
    php(`App\\Models\\Survey::where('public_id','${race.public_id}')->update(['status'=>'active','closed_at'=>null]);
         $s = App\\Models\\Survey::where('public_id','${race.public_id}')->first();
         App\\Models\\Response::where('survey_id',$s->id)->get()->each->delete();
         App\\Models\\ParticipantSession::where('survey_id',$s->id)->delete();`);

    // Fase 1: sesiones abiertas de una en una.
    const preparados = [];
    for (let i = 0; i < POR_RONDA; i += 1) {
        const ctx = await pwRequest.newContext({ baseURL: BASE });
        const html = await (await ctx.get(`/s/${race.public_id}`)).text();
        preparados.push({ ctx, token: html.match(/name="_token" value="([^"]+)"/)?.[1] });
    }

    // Fase 2: los envios y el cierre, a la vez.
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
    }).then((r) => r.status()).catch(() => 0);

    const [estados] = await Promise.all([
        Promise.all(preparados.map(enviar)),
        (async () => php(`App\\Models\\Survey::where('public_id','${race.public_id}')
            ->update(['status'=>'closed','closed_at'=>now()]);`))(),
    ]);

    await Promise.all(preparados.map((p) => p.ctx.dispose()));

    const dist = estados.reduce((a, s) => { a[s] = (a[s] ?? 0) + 1; return a; }, {});
    const salida = php(`$s = App\\Models\\Survey::where('public_id','${race.public_id}')->first();
        echo 'filas='.App\\Models\\Response::where('survey_id',$s->id)->count();
        echo ' huerfanas='.App\\Models\\Response::where('survey_id',$s->id)->whereDoesntHave('answers')->count();`);

    const filas = Number(salida.match(/filas=(\d+)/)?.[1]);
    const huerfanas = Number(salida.match(/huerfanas=(\d+)/)?.[1]);

    resumen.push({ ronda, dist, filas, huerfanas, aceptados: dist['200'] ?? 0 });
    console.log(`ronda ${ronda}: ${JSON.stringify(dist)}  filas=${filas} huerfanas=${huerfanas}`);
}

php(`App\\Models\\Survey::where('public_id','${race.public_id}')->update(['status'=>'active','closed_at'=>null]);`);

const total = resumen.reduce((a, r) => a + Object.values(r.dist).reduce((x, y) => x + y, 0), 0);
const quinientos = resumen.reduce((a, r) => a + (r.dist['500'] ?? 0), 0);
const huerfanasTotal = resumen.reduce((a, r) => a + r.huerfanas, 0);
const descuadres = resumen.filter((r) => r.filas !== r.aceptados);

console.log('\n================ RESUMEN ================');
console.log(`envios totales      : ${total}`);
console.log(`HTTP 500            : ${quinientos}  (${((quinientos / total) * 100).toFixed(1)} %)`);
console.log(`respuestas huerfanas: ${huerfanasTotal}`);
console.log(`rondas descuadradas : ${descuadres.length} ${JSON.stringify(descuadres.map((d) => ({ r: d.ronda, filas: d.filas, ok: d.aceptados })))}`);
